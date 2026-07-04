<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

/**
 * PackagesSeeder — sellable tier catalogue (Starter/Pro/Business/Enterprise)
 * plus the internal "Illimité" pack ordering fix.
 *
 * Registered in DatabaseSeeder (runs on `php artisan db:seed` and
 * `migrate:fresh --seed`). Also runnable standalone:
 *   php artisan db:seed --class=PackagesSeeder
 *
 * Idempotent: updateOrCreate by name, safe to run repeatedly.
 *
 * ── OPERATING RULE — READ BEFORE RE-SEEDING A LIVE PACK ─────────────────────
 * Balances are DERIVED, not stored (see DiscoveryQuotaService — no running
 * balance column, everything is computed at read time from discovery_runs).
 * Editing/re-seeding the numbers on a pack that a client is CURRENTLY assigned
 * to re-scopes the CURRENT period retroactively:
 *   - Upgrading a live pack's numbers is safe (more room appears immediately).
 *   - Downgrading a live pack's numbers mid-month can retroactively exhaust
 *     the client's already-consumed balance and hard-block further runs
 *     (QuotaExhaustedException) for the rest of the period.
 * To change a client's tier cleanly, ASSIGN A DIFFERENT PACK (new
 * PackageAssignment row) — do not edit the numbers on the pack they are
 * currently assigned to.
 */
class PackagesSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name'                    => 'Starter',
                'daily_credits'           => 15,
                'daily_contact_credits'   => 10,
                'monthly_credits'         => 450,
                'monthly_contact_credits' => 300,
                'price_monthly'           => 49,
                'sort_order'              => 10,
            ],
            [
                'name'                    => 'Pro',
                'daily_credits'           => 40,
                'daily_contact_credits'   => 20,
                'monthly_credits'         => 1200,
                'monthly_contact_credits' => 600,
                'price_monthly'           => 99,
                'sort_order'              => 20,
            ],
            [
                'name'                    => 'Business',
                'daily_credits'           => 80,
                'daily_contact_credits'   => 40,
                'monthly_credits'         => 2400,
                'monthly_contact_credits' => 1200,
                'price_monthly'           => 199,
                'sort_order'              => 30,
            ],
            [
                'name'                    => 'Enterprise',
                'daily_credits'           => 150,
                'daily_contact_credits'   => 75,
                'monthly_credits'         => 4500,
                'monthly_contact_credits' => 2250,
                'price_monthly'           => 399,
                'sort_order'              => 40,
            ],
        ];

        foreach ($tiers as $tier) {
            Package::updateOrCreate(
                ['name' => $tier['name']],
                array_merge($tier, [
                    'quota_anchor_date' => null,   // defaults to 1st of month
                    'is_active'         => true,
                ])
            );
        }

        // Keep the internal unlimited pack's sort_order outside the 10–40 tier
        // range so the assign dropdown ordering stays clean. Only touch it if
        // it already exists — this seeder must never CREATE the unlimited pack.
        Package::where('name', 'Illimité')->update(['sort_order' => 99]);
    }
}
