<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Zoho\V2\Sync\ZohoStandardRecoveryService;
use Illuminate\Console\Command;

final class ZohoCrmRecover extends Command
{
    protected $signature = 'zoho:crm:recover';

    protected $description = 'Recover durable Zoho CRM V2 continuation and post-reconciliation work';

    public function handle(ZohoStandardRecoveryService $recovery): int
    {
        $result = $recovery->recover();
        $this->info("Recovered {$result['module_jobs']} module continuation(s) and {$result['post_jobs']} post-reconciliation job(s).");

        return self::SUCCESS;
    }
}
