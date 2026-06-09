<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunZohoCrmSyncJob;
use App\Models\ZohoSyncLog;
use App\Services\Zoho\ZohoCrmSyncService;
use Illuminate\Console\Command;

/**
 * ZohoSyncClients — on-demand Zoho CRM sync command.
 *
 * Usage:
 *   php artisan zoho:sync-clients               # sync all modules synchronously
 *   php artisan zoho:sync-clients --module=Accounts   # accounts only
 *   php artisan zoho:sync-clients --module=Contacts   # contacts only
 *   php artisan zoho:sync-clients --queue        # dispatch async job (default queue)
 *
 * No scheduler registration — triggered on-demand from the UI or CLI per spec.
 */
class ZohoSyncClients extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zoho:sync-clients
                            {--module= : Restrict to a single module: Accounts or Contacts}
                            {--queue   : Dispatch as a queued job instead of running synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Zoho CRM Accounts and Contacts into fretiq companies/contacts tables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $module = $this->option('module') ?: null;
        $queue  = $this->option('queue');

        if ($module !== null && ! in_array($module, ['Accounts', 'Contacts'], true)) {
            $this->error("Invalid --module value '{$module}'. Allowed: Accounts, Contacts");
            return self::FAILURE;
        }

        if ($queue) {
            RunZohoCrmSyncJob::dispatch($module);
            $label = $module ?? 'all modules';
            $this->info("Zoho sync job dispatched for {$label} (async).");
            return self::SUCCESS;
        }

        // ── Synchronous run ───────────────────────────────────────────────────
        $this->info('Starting Zoho CRM sync' . ($module ? " ({$module})" : '') . '…');

        // CrmClient is bound in AppServiceProvider, so container auto-wires ZohoCrmSyncService.
        $service = app(ZohoCrmSyncService::class);

        $startedAt = microtime(true);

        try {
            $service->sync($module);
        } catch (\Throwable $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        // Pull the latest sync log per module (one row each) for a summary line.
        // Iterates over each module name and issues one ->first() query per module
        // rather than a single grouped query, so every module gets its own latest row.
        $modules = $module ? [$module] : ['Accounts', 'Contacts'];
        $logs = collect();
        foreach ($modules as $mod) {
            $row = ZohoSyncLog::where('module', $mod)
                ->latest('synced_at')
                ->first();
            if ($row !== null) {
                $logs->put($mod, $row);
            }
        }

        foreach ($logs as $mod => $log) {
            $this->line(sprintf(
                '  %-10s → %d records  [%s]  %d ms',
                $mod,
                $log->records_synced,
                $log->status,
                $log->duration_ms,
            ));
        }

        $this->info("Zoho sync completed in {$elapsed}s.");
        return self::SUCCESS;
    }
}
