<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\DomainBlocklist;
use Illuminate\Database\Seeder;

/**
 * SettingsSeeder — idempotent default settings.
 *
 * Uses firstOrCreate so existing values are NEVER overwritten.
 * Safe to run on a populated database.
 */
class SettingsSeeder extends Seeder
{
    /**
     * Default settings grouped by group_name.
     *
     * Built in a method rather than a property initialiser because the blocklist
     * defaults come from DomainBlocklist (a method call, not a constant expression).
     * Values MUST stay in sync with the field defaults registered in
     * SettingController::tabs() and the DEFAULT_* constants in
     * HomepageSnapshotService / DiscoveryPipelineService / DomainBlocklist.
     *
     * `planification.*` defaults back App\Services\Scheduling\BusinessCalendarService —
     * an absent/blank `blackout_dates` row means an EMPTY blackout set (not a
     * built-in holiday list, unlike `decouverte.blocked_domains` above).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function defaults(): array
    {
        return [
            'decouverte' => [
                'auto_scoring' => true,
                'auto_enrich' => false,
                'min_score_enrich' => 50,
                'timezone' => 'Europe/Paris',
                'blocked_domains' => DomainBlocklist::defaultDomainsText(),
                'blocked_url_extensions' => DomainBlocklist::defaultExtensionsText(),
                'fetch_homepage' => true,
                'homepage_excerpt_chars' => 2000,
                'homepage_cache_days' => 7,
                'homepage_timeout' => 3,
                'homepage_http_fallback' => false,
                'run_time_budget' => 240,
            ],
            'conformite' => [
                'retention_months' => 18,
            ],
            'envoi' => [
                'timezone' => 'Europe/Paris',
                'daily_cap' => 200,
                'send_window' => 'Mon-Fri 09:00-17:00',
            ],
            'automatisation' => [
                'cron_enabled' => true,
                'campaigns_dispatch_due' => true,
                'campaigns_generate_runs' => true,
                'sequences_process' => true,
                'campaigns_sync_sequence_enrollments' => true,
                'campaign_sync_stats' => true,
                'discovery_terminalize_stale' => true,
                'prospect_auto_discover' => true,
            ],
            'planification' => [
                'skip_weekends' => true,
                'blackout_dates' => '',
            ],
            'zoho' => [
                'auto_sync_enabled' => false,
                'sync_frequency' => 'hourly',
                'nightly_reconciliation_enabled' => false,
            ],
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->defaults() as $group => $keys) {
            foreach ($keys as $key => $value) {
                Setting::firstOrCreate(
                    ['group_name' => $group, 'setting_key' => $key],
                    ['value' => $value]
                );
            }
        }
    }
}
