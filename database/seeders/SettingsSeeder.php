<?php

namespace Database\Seeders;

use App\Models\Setting;
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
     * @var array<string, array<string, mixed>>
     */
    protected array $defaults = [
        'decouverte' => [
            'auto_scoring'     => true,
            'auto_enrich'      => false,
            'min_score_enrich' => 50,
            'timezone'         => 'Europe/Paris',
        ],
        'conformite' => [
            'cold_send_enabled' => false,
            'retention_months'  => 18,
        ],
        'envoi' => [
            'timezone'    => 'Europe/Paris',
            'daily_cap'   => 200,
            'send_window' => 'Mon-Fri 09:00-17:00',
        ],
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        foreach ($this->defaults as $group => $keys) {
            foreach ($keys as $key => $value) {
                Setting::firstOrCreate(
                    ['group_name' => $group, 'setting_key' => $key],
                    ['value' => $value]
                );
            }
        }
    }
}
