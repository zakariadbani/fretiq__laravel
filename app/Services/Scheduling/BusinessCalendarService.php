<?php

namespace App\Services\Scheduling;

use App\Models\Campaign;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * BusinessCalendarService — single source of truth for "is this day allowed to send".
 *
 * Reads two admin settings (Paramètres → Planification):
 *   - planification.skip_weekends  (bool)   — skip Saturday/Sunday
 *   - planification.blackout_dates (string) — one entry per line:
 *         "AAAA-MM-JJ" → one-off blackout date
 *         "MM-JJ"      → recurring blackout date, every year (e.g. "12-25")
 *
 * CRITICAL DIVERGENCE FROM App\Support\DomainBlocklist: an empty `blackout_dates`
 * setting means an EMPTY blackout set, NOT a built-in default holiday list.
 * DomainBlocklist falls back to DEFAULT_DOMAINS when its setting is blank; doing
 * the same here would make it impossible for an admin to ever clear the field
 * back to "no blackout dates" — every empty save would silently re-seed a
 * holiday list. Empty really means empty.
 *
 * Parser shape mirrors DomainBlocklist::parseDomains() (dedupe via `[$key => true]`
 * + array_keys()), but splits on newlines ONLY — commas are legal inside a
 * trailing `# comment` and must not be treated as separators.
 *
 * Carbon convention: `Carbon\CarbonInterface` for read-only "look at this moment"
 * parameters (isBlockedDate, isBlocked, blockedDatesFor), `Carbon\Carbon` for the
 * `shiftToAllowed` parameter/return, matching the concrete-mutable type it derives
 * ("shift THIS instant forward"). Never `Illuminate\Support\Carbon` — this file
 * intentionally avoids mixing the two Carbon families in the same signature.
 */
class BusinessCalendarService
{
    /**
     * Hard bound on shiftToAllowed()'s forward walk. More than a full year of
     * consecutive blocked days is not a legitimate configuration (it's almost
     * certainly an admin mistake, e.g. every day accidentally blacked out) —
     * see shiftToAllowed() for the fail-open rationale.
     */
    private const MAX_SHIFT_ITERATIONS = 366;

    /**
     * Memo key = the raw skip_weekends + blackout_dates setting strings,
     * concatenated with a NUL separator. Self-invalidating: as soon as either
     * raw setting string changes, the key stops matching and the parse re-runs.
     *
     * Correct even though this service is registered as a singleton: SettingService
     * is NOT a singleton (a fresh instance is resolved per use) and its clearCache()
     * does a global Cache::forget('app_settings'), so a Setting::set() elsewhere in
     * the same request/process is immediately visible to Setting::get() here — the
     * memo only needs to react to the STRING changing, not to any external event.
     */
    private ?string $memoKey = null;

    /** @var array{dates: array<string, bool>, annual: array<string, bool>}|null */
    private ?array $blackoutMemo = null;

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * True when weekends (Saturday/Sunday) are globally skipped.
     */
    public function skipWeekends(): bool
    {
        return (bool) Setting::get('planification.skip_weekends', true);
    }

    /**
     * True when $localDate is not an allowed send day.
     *
     * $localDate is assumed to ALREADY be in the target timezone — this method
     * does NOT convert it. Use isBlocked() when you have a moment plus a
     * timezone string instead.
     *
     * Blocked when (skipWeekends() AND the date falls on Sat/Sun) OR the date
     * is in the blackout set (one-off "Y-m-d" match, or recurring "m-d" match).
     */
    public function isBlockedDate(CarbonInterface $localDate): bool
    {
        if ($this->skipWeekends() && $localDate->isWeekend()) {
            return true;
        }

        $sets = $this->blackoutSets();

        return isset($sets['dates'][$localDate->format('Y-m-d')])
            || isset($sets['annual'][$localDate->format('m-d')]);
    }

    /**
     * True when $moment, converted into $tz, falls on a blocked day.
     */
    public function isBlocked(CarbonInterface $moment, string $tz): bool
    {
        return $this->isBlockedDate($moment->copy()->setTimezone($tz));
    }

    /**
     * Nudge $from FORWARD day-by-day in $tz, preserving local wall time, until
     * the result lands on an allowed day. Returns a UTC instant.
     *
     * DST-safe by construction: mirrors PacedCampaignBatchService::computeNextBusinessRun()
     * — `->copy()->setTimezone($tz)` then `addDay()` in LOCAL time, then `->utc()`.
     * NEVER `addHours(24)`, which silently skips or repeats an hour across a
     * DST transition and drifts the local wall-clock time.
     *
     * HARD CONTRACT (do not weaken): shiftToAllowed() must NEVER return an
     * instant earlier than $from, and it is IDEMPOTENT on an already-allowed
     * input (returns an instant equal to $from, converted to UTC — no shift
     * applied). PacedCampaignBatchService::evaluateDue()'s unbounded `while`
     * loops at :116-118 and :130-132 rely on this invariant to terminate; a
     * version that "always advances at least one day" would break them.
     *
     * Bound: stops after self::MAX_SHIFT_ITERATIONS (366) forward steps. This
     * is a FAIL-OPEN bound, deliberately: it logs an error and returns $from
     * UNSHIFTED rather than looping forever or throwing. Fail-closed (throwing,
     * or looping without a bound) would silently stall EVERY campaign that
     * depends on this method forever, with no visible symptom — a scheduler
     * that never advances looks identical to "nothing is due yet". Fail-open
     * at worst sends on a day that should have been blocked (a misconfigured
     * blackout list), which is visible in the sent log and correctable. A
     * legitimate blackout configuration never blocks 366+ consecutive days.
     */
    public function shiftToAllowed(Carbon $from, string $tz): Carbon
    {
        $local = $from->copy()->setTimezone($tz);
        $iterations = 0;

        while ($this->isBlockedDate($local)) {
            $iterations++;

            if ($iterations > self::MAX_SHIFT_ITERATIONS) {
                Log::error('[BusinessCalendarService] shiftToAllowed exceeded '.self::MAX_SHIFT_ITERATIONS.' consecutive blocked days; returning unshifted (fail-open)', [
                    'from' => $from->toIso8601String(),
                    'tz' => $tz,
                ]);

                return $from->copy()->utc();
            }

            $local->addDay();
        }

        return $local->utc();
    }

    /**
     * List of 'Y-m-d' strings blocked within [$fromLocal, $fromLocal + $days) —
     * EVERY non-working day: plain weekends (when skipWeekends() is on) PLUS
     * blackout-list entries. Used by the planner (calendar shading), where the
     * point is to shade every day nothing can send on.
     *
     * Do NOT use this for the settings preview — with skip_weekends on (the
     * default), the first N entries are almost always just the next weekends,
     * drowning out the admin's actual blackout entries. Use blackoutDatesFor()
     * for that instead.
     *
     * @return list<string>
     */
    public function blockedDatesFor(CarbonInterface $fromLocal, int $days): array
    {
        $blocked = [];
        $cursor = $fromLocal->copy();

        for ($i = 0; $i < $days; $i++) {
            if ($this->isBlockedDate($cursor)) {
                $blocked[] = $cursor->format('Y-m-d');
            }

            $cursor = $cursor->copy()->addDay();
        }

        return $blocked;
    }

    /**
     * List of 'Y-m-d' strings within [$fromLocal, $fromLocal + $days) that are
     * matched by the BLACKOUT LIST ONLY (the parsed `dates` + `annual` sets) —
     * plain weekends are NOT included, even when skipWeekends() is on. Used by
     * the settings preview (blackout_preview) to give the admin feedback that
     * what they typed in the textarea actually parsed, without that feedback
     * being drowned out by the next few Saturdays/Sundays.
     *
     * Edge case: a blackout entry that happens to fall on a weekend is still a
     * legitimate list entry and DOES appear here — the filter is "is this date
     * in the blackout set", not "is this a blocked day that isn't a weekend".
     *
     * $fromLocal is assumed to already be in the target timezone.
     *
     * @return list<string>
     */
    public function blackoutDatesFor(CarbonInterface $fromLocal, int $days): array
    {
        $sets = $this->blackoutSets();
        $blackout = [];
        $cursor = $fromLocal->copy();

        for ($i = 0; $i < $days; $i++) {
            $isBlackout = isset($sets['dates'][$cursor->format('Y-m-d')])
                || isset($sets['annual'][$cursor->format('m-d')]);

            if ($isBlackout) {
                $blackout[] = $cursor->format('Y-m-d');
            }

            $cursor = $cursor->copy()->addDay();
        }

        return $blackout;
    }

    /**
     * Resolve the timezone to evaluate a campaign's schedule in: the campaign's
     * own timezone when given, else the global discovery/planning default.
     */
    public function resolveTimezone(?Campaign $campaign): string
    {
        return $campaign?->scheduleTimezone() ?? (string) Setting::get('decouverte.timezone', 'Europe/Paris');
    }

    /**
     * Lines from $raw that failed to parse as either a one-off ("AAAA-MM-JJ")
     * or recurring ("MM-JJ") blackout entry, in the order they appear. Used by
     * SettingController::save() to fail loudly on a malformed blackout list —
     * unlike DomainBlocklist, a typo'd holiday must not be silently dropped,
     * because the visible failure mode is "campaign sends on Christmas".
     *
     * @return list<string>
     */
    public static function invalidLines(string $raw): array
    {
        return self::parseLines($raw)['invalid'];
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * Memoized parse of the two planification.* settings into lookup sets.
     *
     * @return array{dates: array<string, bool>, annual: array<string, bool>}
     */
    private function blackoutSets(): array
    {
        $skipRaw = $this->skipWeekends() ? '1' : '0';
        $blackoutRaw = $this->rawBlackoutDates();
        $key = $skipRaw."\x00".$blackoutRaw;

        if ($this->blackoutMemo !== null && $this->memoKey === $key) {
            return $this->blackoutMemo;
        }

        $parsed = self::parseLines($blackoutRaw);

        $this->memoKey = $key;
        $this->blackoutMemo = ['dates' => $parsed['dates'], 'annual' => $parsed['annual']];

        return $this->blackoutMemo;
    }

    private function rawBlackoutDates(): string
    {
        $value = Setting::get('planification.blackout_dates', '');

        return is_string($value) ? $value : '';
    }

    /**
     * Parse a raw blackout_dates textarea value into one-off dates, recurring
     * annual dates, and invalid (dropped) lines.
     *
     * 1. Split on newlines ONLY (`preg_split('/[\r\n]+/', ...)`, CRLF-safe) —
     *    commas are legal inside a trailing `# comment` and must not split a line.
     * 2. Strip everything from the first `#` (comment), then trim(); skip blanks.
     * 3. `/^\d{4}-\d{2}-\d{2}$/` → one-off date, validated with checkdate().
     *    `/^\d{2}-\d{2}$/`      → recurring annual date, validated against a
     *    fixed leap-year reference (2000) so "02-29" is accepted as a marker —
     *    it simply never matches isBlockedDate() lookups in a non-leap year,
     *    which needs no special-casing at lookup time (two isset() calls).
     *    Anything else → invalid, collected for invalidLines().
     * 4. Dedupe via `[$key => true]` + the caller reading array_keys()-shaped
     *    data, same idiom as DomainBlocklist::parseDomains().
     *
     * @return array{dates: array<string, bool>, annual: array<string, bool>, invalid: list<string>}
     */
    private static function parseLines(string $raw): array
    {
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];

        $dates = [];
        $annual = [];
        $invalid = [];

        foreach ($lines as $line) {
            $hashPos = strpos($line, '#');
            if ($hashPos !== false) {
                $line = substr($line, 0, $hashPos);
            }

            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $line) === 1) {
                [$year, $month, $day] = array_map('intval', explode('-', $line));

                if (checkdate($month, $day, $year)) {
                    $dates[$line] = true;
                } else {
                    $invalid[] = $line;
                }

                continue;
            }

            if (preg_match('/^\d{2}-\d{2}$/', $line) === 1) {
                [$month, $day] = array_map('intval', explode('-', $line));

                // Reference year 2000 is a leap year, so "02-29" validates as a
                // recurring marker here even though it only matches every 4 years.
                if (checkdate($month, $day, 2000)) {
                    $annual[$line] = true;
                } else {
                    $invalid[] = $line;
                }

                continue;
            }

            $invalid[] = $line;
        }

        return ['dates' => $dates, 'annual' => $annual, 'invalid' => $invalid];
    }
}
