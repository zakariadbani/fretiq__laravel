<?php

namespace App\Services\Zoho\V2\Inventory;

/** Sanitised inventory outcome: deliberately contains no CRM record data. */
final readonly class InventoryReport
{
    /** @param array<string, array{status:int,state:string,schema_hash:?string,fields:int,layouts:int,related_lists:int,currencies:list<string>}> $modules */
    public function __construct(
        public array $modules,
        public int $discoveredModules,
        public int $failedModules = 0,
        public bool $complete = true,
        public string $status = 'healthy',
    ) {}
}
