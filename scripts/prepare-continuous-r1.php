<?php

// Local-only preparation. Default is a read-only preview; --apply-local is explicit.
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'fretiq') {
    throw new RuntimeException('This preparation is restricted to the local fretiq database.');
}
$apply = in_array('--apply-local', $argv, true);
$result = DB::transaction(function () use ($apply): array {
    $campaigns = App\Models\Campaign::where('name', 'R1 pilote Europe Maroc — 2026-09-09')->lockForUpdate()->get();
    $segments = App\Models\Segment::where('name', 'R1 recherche ouvreurs sans clic — 2026-09-09')->lockForUpdate()->get();
    if ($campaigns->count() !== 1 || $segments->count() !== 1) {
        throw new RuntimeException('Expected exactly one September 9 R1 campaign and research segment.');
    }
    $campaign = $campaigns->first();
    $segment = $segments->first();
    $sequence = $campaign->sequence;
    if ($campaign->is_active || $campaign->sequence_auto_enroll_enabled || $sequence?->is_active
        || $sequence?->name !== 'Séquence R1 Europe Maroc — 2026-09-09'
        || $sequence->steps()->count() !== 1 || $segment->is_manual || $segment->scope !== 'prospect') {
        throw new RuntimeException('R1 must retain its inactive single-step sequence and dynamic prospect segment.');
    }
    $filter = $segment->filter;
    $engagement = $filter['engagement'] ?? [];
    $sourceIds = $engagement['campaign_id'] ?? [];
    sort($sourceIds);
    if ($sourceIds !== [1, 2, 3] || ($engagement['opened'] ?? null) !== true
        || ($engagement['clicked'] ?? null) !== false || ($engagement['sans_demande'] ?? null) !== true) {
        throw new RuntimeException('The research cohort changed; review it before preparation.');
    }
    $filter['prospecting_rules'] = ['enabled' => true, 'contact_gap_days' => 7, 'verification_max_age_days' => 30,
        'one_contact_per_company' => true, 'once_per_sequence_company' => true,
        'exclude_engaged_companies' => true, 'exclude_pending_companies' => true];
    $firstBatch = $campaign->next_run_at ?? app(App\Services\Campaign\PacedSequenceEnrollmentService::class)
        ->computeNextBusinessRun(now()->setTimezone($campaign->scheduleTimezone())->startOfDay()->setHour(9), $campaign->scheduleTimezone())->utc();
    $changes = ['segment_id' => $segment->id, 'schedule_type' => 'sequence', 'sequence_enrollment_mode' => 'paced',
        'delivery_channel' => 'smtp', 'email_verification_policy' => 'verified_only', 'daily_company_limit' => 10,
        'smtp_daily_email_limit' => 10, 'next_run_at' => $firstBatch, 'timezone' => $campaign->scheduleTimezone(),
        'is_active' => false, 'sequence_auto_enroll_enabled' => false];
    $segment->filter = $filter;
    $campaign->fill($changes)->setRelation('segment', $segment);
    if ($apply) {
        // No observers, jobs, enrollment, transport, or sequence activation.
        $segment->saveQuietly();
        $campaign->saveQuietly();
    }
    $preview = app(App\Services\Campaign\PacedSequenceEnrollmentService::class)->previewDailyBatch($campaign);

    return ['mode' => $apply ? 'applied_inactive_local' : 'read_only_preview', 'observed_at' => now()->toIso8601String(),
        'campaign_id' => $campaign->id, 'segment_id' => $segment->id, 'sequence_id' => $sequence->id,
        'settings' => $changes, 'prospecting_rules' => $filter['prospecting_rules'], 'preview' => $preview,
        'records' => ['recipients' => App\Models\CampaignRecipient::count(), 'enrollments' => App\Models\SequenceEnrollment::count(),
            'reservations' => App\Models\SmtpSendReservation::count(), 'jobs' => DB::table('jobs')->count()]];
});
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
