<?php

namespace App\Services\Zoho\V2\Contracts;

use App\Services\Zoho\V2\DTO\SyncCheckpoint;
use App\Services\Zoho\V2\DTO\SyncResult;
use App\Services\Zoho\V2\DTO\SyncRunContext;
use App\Services\Zoho\V2\Registry\ModuleDefinition;

interface ZohoModuleSynchronizer
{
    public function synchronize(ModuleDefinition $module, string $mode, SyncCheckpoint $checkpoint, SyncRunContext $context): SyncResult;
}
