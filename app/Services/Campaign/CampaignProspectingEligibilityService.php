<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SequenceEnrollment;
use App\Models\SmtpSendReservation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Campaign-only guards for an explicitly opted-in paced SMTP prospect pool.
 *
 * Reason taxonomy:
 *   - SAFETY (re-checked at select(), enroll() opt-in recheck, and send time;
 *     a hit is permanent -> enrollment stopped): company_not_prospect,
 *     suppressed, engaged, bounced, invalid_email, verification_stale.
 *   - SELECTION-only (select() only, never blocks an existing enrollment's
 *     send): already_enrolled, and a broad pending_work (any active
 *     enrollment/recipient/reservation for the company, regardless of
 *     campaign).
 *   - DEFER (send time only, bounded): contacted_recently (latest send to the
 *     company by ANOTHER campaign -> defer to latest + contact_gap_days) and
 *     a narrow pending_work (active SMTP work from an OTHER, OLDER campaign
 *     on the same company, created within 24h -> defer +15 min; never blocks
 *     forever since a stale/zombie row ages out of the 24h window).
 */
class CampaignProspectingEligibilityService
{
    public function optedIn(Campaign $campaign): bool
    {
        return $campaign->delivery_channel === 'smtp'
            && $campaign->schedule_type === 'sequence'
            && $campaign->sequence_enrollment_mode === 'paced'
            && (bool) (($campaign->segment?->filter ?? [])['prospecting_rules']['enabled'] ?? false);
    }

    /** @return array{enabled:bool,contact_gap_days:int,verification_max_age_days:int,one_contact_per_company:bool,once_per_sequence_company:bool,exclude_engaged_companies:bool,exclude_pending_companies:bool} */
    public function rules(Campaign $campaign): array
    {
        $raw = (array) (($campaign->segment?->filter ?? [])['prospecting_rules'] ?? []);

        return [
            'enabled' => $this->optedIn($campaign),
            'contact_gap_days' => max(1, min(365, (int) ($raw['contact_gap_days'] ?? 7))),
            'verification_max_age_days' => max(1, min(365, (int) ($raw['verification_max_age_days'] ?? 30))),
            'one_contact_per_company' => ! array_key_exists('one_contact_per_company', $raw) || (bool) $raw['one_contact_per_company'],
            'once_per_sequence_company' => ! array_key_exists('once_per_sequence_company', $raw) || (bool) $raw['once_per_sequence_company'],
            'exclude_engaged_companies' => ! array_key_exists('exclude_engaged_companies', $raw) || (bool) $raw['exclude_engaged_companies'],
            'exclude_pending_companies' => ! array_key_exists('exclude_pending_companies', $raw) || (bool) $raw['exclude_pending_companies'],
        ];
    }

    /**
     * Select eligible contacts from already-ranked (ai_score desc / company_id
     * asc) company groups, stopping once $limit eligible companies are found.
     * The caller owns the campaign lock; this method performs no provider
     * work and is safe for previews.
     *
     * Company-level facts (company_not_prospect, suppressed, engaged,
     * bounced, already_enrolled, pending_work) are evaluated once per company
     * group; only trashed/invalid_email/verification_stale are re-checked per
     * contact.
     *
     * // ponytail: per-company queries; switch to set-based queries if the
     * // scan exceeds ~200 companies/tick.
     *
     * @param  Collection<int|string, Collection<int, Contact>>  $rankedGroups  Contacts grouped by company_id, already ranked.
     * @return array{contacts:Collection<int,Contact>,excluded:array<string,int>,scanned_companies:int,scan_capped:bool}
     */
    public function select(Campaign $campaign, Collection $rankedGroups, int $limit, ?int $maxScan = null): array
    {
        $rules = $this->rules($campaign);
        if (! $rules['enabled']) {
            return ['contacts' => $rankedGroups->flatten(1)->values(), 'excluded' => [], 'scanned_companies' => $rankedGroups->count(), 'scan_capped' => false];
        }

        $excluded = [];
        $selected = collect();
        $scanned = 0;
        $companiesSelected = 0;
        $maxScan ??= max(200, $limit * 20);
        $scanCapped = false;

        foreach ($rankedGroups as $contacts) {
            if ($companiesSelected >= $limit) {
                break;
            }
            if ($scanned >= $maxScan) {
                $scanCapped = true;
                break;
            }
            $scanned++;

            $company = $contacts->first()?->company;
            $companyOutcome = $this->companySafety($company, $rules);
            if ($companyOutcome['reason'] !== null) {
                $excluded[$companyOutcome['reason']] = ($excluded[$companyOutcome['reason']] ?? 0) + 1;

                continue;
            }

            if ($rules['once_per_sequence_company'] && SequenceEnrollment::query()
                ->where('sequence_id', $campaign->sequence_id)
                ->whereIn('contact_id', $companyOutcome['ids'])
                ->exists()) {
                $excluded['already_enrolled'] = ($excluded['already_enrolled'] ?? 0) + 1;

                continue;
            }

            if ($rules['exclude_pending_companies'] && $this->hasBroadPendingWork($companyOutcome['ids'])) {
                $excluded['pending_work'] = ($excluded['pending_work'] ?? 0) + 1;

                continue;
            }

            $ordered = $contacts->sort(function (Contact $left, Contact $right): int {
                $freshness = ($right->email_verification_checked_at?->timestamp ?? 0) <=> ($left->email_verification_checked_at?->timestamp ?? 0);

                return $freshness !== 0 ? $freshness : $left->id <=> $right->id;
            })->values();
            $eligible = $ordered->filter(fn (Contact $candidate) => $this->contactSafety($candidate, $rules) === null);
            if ($eligible->isEmpty()) {
                $candidate = $ordered->first();
                $reason = $candidate !== null ? ($this->contactSafety($candidate, $rules) ?? 'company_not_prospect') : 'company_not_prospect';
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;

                continue;
            }

            $selected = $rules['one_contact_per_company'] ? $selected->push($eligible->first()) : $selected->concat($eligible);
            $companiesSelected++;
        }

        return ['contacts' => $selected, 'excluded' => $excluded, 'scanned_companies' => $scanned, 'scan_capped' => $scanCapped];
    }

    /**
     * SAFETY-only recheck for a single contact — used by SequenceService::enroll()'s
     * opt-in recheck. Deliberately excludes already_enrolled/pending_work (selection
     * concerns, not safety) so it can never under-fill a batch.
     */
    public function safetyReason(Campaign $campaign, Contact $contact): ?string
    {
        $rules = $this->rules($campaign);
        if (! $rules['enabled']) {
            return null;
        }

        $companyOutcome = $this->companySafety($contact->company, $rules);
        if ($companyOutcome['reason'] !== null) {
            return $companyOutcome['reason'];
        }

        return $this->contactSafety($contact, $rules);
    }

    /**
     * Send-time reason for a single already-enrolled contact: SAFETY (permanent
     * -> stop), narrow pending_work, or contacted_recently (both DEFER, bounded).
     * Null means eligible to send now.
     */
    public function sendReason(Campaign $campaign, Contact $contact, ?int $currentReservationId = null): ?string
    {
        $rules = $this->rules($campaign);
        if (! $rules['enabled']) {
            return null;
        }

        $companyOutcome = $this->companySafety($contact->company, $rules);
        if ($companyOutcome['reason'] !== null) {
            return $companyOutcome['reason'];
        }

        $contactOutcome = $this->contactSafety($contact, $rules);
        if ($contactOutcome !== null) {
            return $contactOutcome;
        }

        if ($rules['exclude_pending_companies'] && $this->hasNarrowPendingWork($campaign, $companyOutcome['ids'], $currentReservationId)) {
            return 'pending_work';
        }

        $latest = $this->latestContactAt($companyOutcome['ids'], (int) $campaign->id);
        if ($latest !== null && $latest->gt(now()->subDays($rules['contact_gap_days']))) {
            return 'contacted_recently';
        }

        return null;
    }

    /**
     * The exact defer target for a 'contacted_recently' sendReason() outcome:
     * the latest other-campaign send to this contact's company, plus the
     * configured gap. Only meaningful when sendReason() returned that reason.
     */
    public function contactedRecentlyDeferUntil(Campaign $campaign, Contact $contact): ?\Carbon\Carbon
    {
        $company = $contact->company;
        if ($company === null) {
            return null;
        }

        $latest = $this->latestContactAt($this->companyContext($company)['ids'], (int) $campaign->id);

        return $latest?->copy()->addDays($this->rules($campaign)['contact_gap_days']);
    }

    /**
     * Company-level SAFETY facts, evaluated once per company group.
     *
     * @return array{reason:?string,ids:Collection<int,int>}
     */
    private function companySafety(?Company $company, array $rules): array
    {
        if ($company === null || ! $company->is_active || strtolower(trim((string) $company->relationship)) !== 'prospect') {
            return ['reason' => 'company_not_prospect', 'ids' => collect()];
        }

        $context = $this->companyContext($company);
        $ids = $context['ids'];
        $emails = $context['emails'];

        if (DB::table('suppressions')->whereIn(DB::raw('LOWER(TRIM(email))'), $emails)->exists()) {
            return ['reason' => 'suppressed', 'ids' => $ids];
        }
        if ($rules['exclude_engaged_companies'] && (
            DB::table('demandes')->whereIn('contact_id', $ids)->exists()
            || DB::table('inbox_emails')->where(function ($q) use ($ids, $emails): void {
                $q->whereIn('contact_id', $ids)->orWhereIn(DB::raw('LOWER(TRIM(from_email))'), $emails);
            })->exists()
            || DB::table('campaign_recipients')->whereIn('contact_id', $ids)->where(function ($q): void {
                $q->whereIn('status', ['replied', 'unsubscribed'])->orWhereNotNull('replied_at');
            })->exists()
            || SequenceEnrollment::whereIn('contact_id', $ids)->whereIn('stopped_reason', ['replied', 'unsubscribe', 'unsubscribed'])->exists()
        )) {
            return ['reason' => 'engaged', 'ids' => $ids];
        }
        if (Contact::withTrashed()->whereIn('id', $ids)->where('email_verification_source', 'bounce')->exists()
            || DB::table('campaign_recipients')->whereIn('contact_id', $ids)->where(function ($q): void {
                $q->where('status', 'bounced')->orWhereNotNull('bounced_at');
            })->exists()) {
            return ['reason' => 'bounced', 'ids' => $ids];
        }

        return ['reason' => null, 'ids' => $ids];
    }

    /** Contact-level SAFETY facts: trashed, invalid_email, verification_stale. No queries. */
    private function contactSafety(Contact $contact, array $rules): ?string
    {
        if ($contact->trashed()) {
            return 'company_not_prospect';
        }
        $verificationStatus = strtolower(trim((string) $contact->email_verification_status));
        if (in_array($verificationStatus, ['invalid', 'disposable'], true)) {
            return 'invalid_email';
        }
        $checked = $contact->email_verification_checked_at;
        if ($verificationStatus !== 'valid' || $checked === null || $checked->isFuture()
            || $checked->lt(now()->subDays($rules['verification_max_age_days']))) {
            return 'verification_stale';
        }

        return null;
    }

    /**
     * Widened company contact-id set (aliases via matching email, including
     * tombstones) plus the pre-widen email list used for the suppression check.
     *
     * @return array{ids:Collection<int,int>,emails:array<string>}
     */
    private function companyContext(Company $company): array
    {
        $exactIds = Contact::withTrashed()->where('company_id', $company->id)->pluck('id');
        $emails = Contact::withTrashed()->whereIn('id', $exactIds)->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))->filter()->all();
        // Historical aliases can belong to another contact row, including a tombstone.
        $widenedIds = Contact::withTrashed()->where(function ($q) use ($company, $emails): void {
            $q->where('company_id', $company->id)->orWhereIn(DB::raw('LOWER(TRIM(email))'), $emails);
        })->pluck('id');

        return ['ids' => $widenedIds, 'emails' => $emails];
    }

    /** SELECTION-only broad pending-work check: any active work at all, any campaign. */
    private function hasBroadPendingWork(Collection $ids): bool
    {
        if (SequenceEnrollment::query()->whereIn('contact_id', $ids)->whereIn('status', ['active', 'paused'])->exists()) {
            return true;
        }
        if (DB::table('campaign_recipients')->whereIn('contact_id', $ids)->whereIn('status', ['queued', 'sending'])->exists()) {
            return true;
        }
        if (DB::table('sequence_step_sends')->join('sequence_enrollments', 'sequence_enrollments.id', '=', 'sequence_step_sends.enrollment_id')
            ->whereIn('sequence_enrollments.contact_id', $ids)->where('sequence_step_sends.status', 'queued')->exists()) {
            return true;
        }
        $sequenceSources = DB::table('sequence_step_sends')->join('sequence_enrollments', 'sequence_enrollments.id', '=', 'sequence_step_sends.enrollment_id')
            ->whereIn('sequence_enrollments.contact_id', $ids)->pluck('sequence_step_sends.id');
        $recipientSources = DB::table('campaign_recipients')->whereIn('contact_id', $ids)->pluck('id');

        return SmtpSendReservation::query()->whereIn('status', ['reserved', 'sending', 'accepted', 'uncertain'])
            ->where(function ($q) use ($sequenceSources, $recipientSources): void {
                $q->where(function ($sequence) use ($sequenceSources): void {
                    $sequence->where('source_type', SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND)->whereIn('source_id', $sequenceSources);
                })->orWhere(function ($recipient) use ($recipientSources): void {
                    $recipient->where('source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)->whereIn('source_id', $recipientSources);
                });
            })
            ->exists();
    }

    /**
     * DEFER-only narrow pending-work check: an active SmtpSendReservation from
     * an OTHER, OLDER campaign on the same company, created within the last
     * 24h (a stale/zombie row ages out and no longer blocks). "Older" is
     * decided strictly by reservation id, not created_at — comparing against
     * an enrollment's or recipient's created_at let two opted-in campaigns on
     * the same company each see the other as "older" (an enrollment always
     * predates its own reservation), causing a mutual 24h defer livelock.
     * Requires a current reservation to compare against — with none, there
     * is nothing to defer.
     */
    private function hasNarrowPendingWork(Campaign $campaign, Collection $ids, ?int $currentReservationId): bool
    {
        if ($currentReservationId === null) {
            return false;
        }
        $cutoff = now()->subHours(24);

        $sequenceSources = DB::table('sequence_step_sends')->join('sequence_enrollments', 'sequence_enrollments.id', '=', 'sequence_step_sends.enrollment_id')
            ->whereIn('sequence_enrollments.contact_id', $ids)->pluck('sequence_step_sends.id');
        $recipientSources = DB::table('campaign_recipients')->whereIn('contact_id', $ids)->pluck('id');

        return SmtpSendReservation::query()
            ->whereIn('status', ['reserved', 'sending', 'accepted', 'uncertain'])
            ->where('campaign_id', '!=', $campaign->id)
            ->where('id', '<', $currentReservationId)
            ->where('created_at', '>=', $cutoff)
            ->where(function ($q) use ($sequenceSources, $recipientSources): void {
                $q->where(function ($sequence) use ($sequenceSources): void {
                    $sequence->where('source_type', SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND)->whereIn('source_id', $sequenceSources);
                })->orWhere(function ($recipient) use ($recipientSources): void {
                    $recipient->where('source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)->whereIn('source_id', $recipientSources);
                });
            })
            ->exists();
    }

    /** Latest send to the company by a campaign OTHER than $excludeCampaignId. */
    private function latestContactAt(Collection $ids, int $excludeCampaignId): ?\Carbon\Carbon
    {
        $dates = collect([
            DB::table('campaign_recipients')
                ->join('campaign_runs', 'campaign_runs.id', '=', 'campaign_recipients.campaign_run_id')
                ->whereIn('campaign_recipients.contact_id', $ids)
                ->where('campaign_runs.campaign_id', '!=', $excludeCampaignId)
                ->max('campaign_recipients.sent_at'),
            DB::table('sequence_step_sends')
                ->join('sequence_enrollments', 'sequence_enrollments.id', '=', 'sequence_step_sends.enrollment_id')
                ->whereIn('sequence_enrollments.contact_id', $ids)
                ->where(function ($q) use ($excludeCampaignId): void {
                    // Legacy rows predate campaign attribution on sequence_enrollments;
                    // treat a NULL campaign_id as "another campaign" — the safe side is
                    // to count them toward contacted_recently rather than ignore them.
                    $q->whereNull('sequence_enrollments.campaign_id')->orWhere('sequence_enrollments.campaign_id', '!=', $excludeCampaignId);
                })
                ->max('sequence_step_sends.sent_at'),
            DB::table('smtp_send_reservations')->join('sequence_step_sends', function ($join): void {
                $join->on('smtp_send_reservations.source_id', '=', 'sequence_step_sends.id')
                    ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND);
            })->join('sequence_enrollments', 'sequence_enrollments.id', '=', 'sequence_step_sends.enrollment_id')
                ->whereIn('sequence_enrollments.contact_id', $ids)
                ->where(function ($q) use ($excludeCampaignId): void {
                    $q->whereNull('smtp_send_reservations.campaign_id')->orWhere('smtp_send_reservations.campaign_id', '!=', $excludeCampaignId);
                })
                ->selectRaw('MAX(COALESCE(smtp_send_reservations.accepted_at, smtp_send_reservations.sent_at)) as latest')->value('latest'),
            DB::table('smtp_send_reservations')->join('campaign_recipients', function ($join): void {
                $join->on('smtp_send_reservations.source_id', '=', 'campaign_recipients.id')
                    ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT);
            })->whereIn('campaign_recipients.contact_id', $ids)
                ->where(function ($q) use ($excludeCampaignId): void {
                    $q->whereNull('smtp_send_reservations.campaign_id')->orWhere('smtp_send_reservations.campaign_id', '!=', $excludeCampaignId);
                })
                ->selectRaw('MAX(COALESCE(smtp_send_reservations.accepted_at, smtp_send_reservations.sent_at)) as latest')->value('latest'),
        ])->filter();

        return $dates->isEmpty() ? null : \Carbon\Carbon::parse($dates->max());
    }
}
