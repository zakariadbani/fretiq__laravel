<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRun extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'campaign_runs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'campaign_id',
        'sequence_step_id',
        'occurrence_key',
        'run_at',
        'status',
        'stats_sent',
        'stats_delivered',
        'stats_opened',
        'stats_clicked',
        'stats_bounced',
        'stats_unsubscribed',
        'stats_replied',
        'conversion_count',
        'zoho_list_key',
        'zoho_campaign_key',
        'driver_ref',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'run_at'      => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The parent campaign.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function sequenceStep(): BelongsTo
    {
        return $this->belongsTo(SequenceStep::class);
    }

    /**
     * All individual recipient records for this run.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** Company ledger entries claimed by this paced batch. */
    public function companyDispatches(): HasMany
    {
        return $this->hasMany(CampaignCompanyDispatch::class, 'current_run_id');
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'campaign_id'    => 'required|integer|exists:campaigns,id',
            'occurrence_key' => 'required|string|max:64',
            'run_at'         => 'required|date',
            'status'         => 'nullable|' . ConfigEnum::in('campaign_run_statuses'),
            'stats_sent'         => 'nullable|integer|min:0',
            'stats_delivered'    => 'nullable|integer|min:0',
            'stats_opened'       => 'nullable|integer|min:0',
            'stats_clicked'      => 'nullable|integer|min:0',
            'stats_bounced'      => 'nullable|integer|min:0',
            'stats_unsubscribed' => 'nullable|integer|min:0',
            'stats_replied'      => 'nullable|integer|min:0',
            'conversion_count'   => 'nullable|integer|min:0',
        ];
    }

    // ── Computed metrics ───────────────────────────────────────────────────────

    /** A run counts toward campaign KPIs only once its send completed. */
    public function isExecuted(): bool
    {
        return $this->status === 'sent';
    }

    /** @param Builder<CampaignRun> $query */
    public function scopeExecuted(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    /** Normalize a stored/provider count without letting malformed legacy data leak into KPIs. */
    public static function normalizeKpi(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        $value = (float) $value;

        return is_finite($value) && $value >= 0 ? (int) $value : 0;
    }

    /**
     * Shared per-run presentation values. Rates use delivered where it exists,
     * otherwise sent; no denominator is represented as null rather than 0%.
     *
     * @return array<string, int|float|null>
     */
    public function kpis(): array
    {
        return self::kpisFromCounts([
            'sent'         => $this->stats_sent,
            'delivered'    => $this->stats_delivered,
            'opened'       => $this->stats_opened,
            'clicked'      => $this->stats_clicked,
            'replied'      => $this->stats_replied,
            'bounced'      => $this->stats_bounced,
            'unsubscribed' => $this->stats_unsubscribed,
            'conversions'  => $this->conversion_count,
        ]);
    }

    /**
     * Aggregate only completed runs so callers cannot accidentally include
     * prepared/scheduled snapshots in campaign totals.
     *
     * @param iterable<CampaignRun> $runs
     * @return array<string, int|float|null>
     */
    public static function aggregateKpis(iterable $runs): array
    {
        $totals = array_fill_keys([
            'sent', 'delivered', 'opened', 'clicked', 'replied', 'bounced', 'unsubscribed', 'conversions',
        ], 0);

        foreach ($runs as $run) {
            if (! $run instanceof self || ! $run->isExecuted()) {
                continue;
            }

            $runKpis = $run->kpis();

            foreach ($totals as $key => $total) {
                $totals[$key] = $total + $runKpis[$key];
            }
        }

        $kpis = self::kpisFromCounts($totals);

        foreach ($totals as $key => $total) {
            $kpis['total_' . $key] = $total;
        }

        return $kpis;
    }

    /** Return a UI-safe one-decimal percentage label. */
    public static function rateLabel(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1, '.', '') . '%';
    }

    /** Open rate as a percentage, or null when no honest denominator exists. */
    public function openRate(): ?float
    {
        return $this->kpis()['open_rate'];
    }

    /** Click rate as a percentage, or null when no honest denominator exists. */
    public function clickRate(): ?float
    {
        return $this->kpis()['click_rate'];
    }

    /** Conversion rate as a percentage, or null when no honest denominator exists. */
    public function conversionRate(): ?float
    {
        return $this->kpis()['conversion_rate'];
    }

    /**
     * @param array<string, mixed> $counts
     * @return array<string, int|float|null>
     */
    private static function kpisFromCounts(array $counts): array
    {
        $sent         = self::normalizeKpi($counts['sent'] ?? null);
        $delivered    = self::normalizeKpi($counts['delivered'] ?? null);
        $opened       = self::normalizeKpi($counts['opened'] ?? null);
        $clicked      = self::normalizeKpi($counts['clicked'] ?? null);
        $replied      = self::normalizeKpi($counts['replied'] ?? null);
        $bounced      = self::normalizeKpi($counts['bounced'] ?? null);
        $unsubscribed = self::normalizeKpi($counts['unsubscribed'] ?? null);
        $conversions  = self::normalizeKpi($counts['conversions'] ?? null);
        $denominator  = $delivered > 0 ? $delivered : ($sent > 0 ? $sent : null);

        return [
            'sent'         => $sent,
            'delivered'    => $delivered,
            'opened'       => $opened,
            'clicked'      => $clicked,
            'replied'      => $replied,
            'bounced'      => $bounced,
            'unsubscribed' => $unsubscribed,
            'conversions'  => $conversions,
            'denominator'  => $denominator,
            'open_rate'    => self::rate($opened, $denominator),
            'click_rate'   => self::rate($clicked, $denominator),
            'conversion_rate' => self::rate($conversions, $denominator),
        ];
    }

    private static function rate(int $numerator, ?int $denominator): ?float
    {
        return $denominator === null ? null : round(($numerator / $denominator) * 100, 2);
    }
}
