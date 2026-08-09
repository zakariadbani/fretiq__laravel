<?php

declare(strict_types=1);

namespace App\Services\Zoho\V2\PostReconciliation;

use App\Models\Zoho\ZohoSyncBatch;
use App\Services\Zoho\V2\Identity\ZohoIdentityLinker;
use App\Services\Zoho\V2\Inventory\ZohoInventoryService;
use App\Services\Zoho\V2\Reconciliation\ZohoFailureRetryService;
use RuntimeException;

final class DefaultZohoPostReconciliationProcessor implements ZohoPostReconciliationProcessor
{
    public function __construct(
        private readonly ZohoFailureRetryService $failures,
        private readonly ZohoInventoryService $inventory,
        private readonly ZohoIdentityLinker $identities,
    ) {}

    public function process(int $batchId): array
    {
        $batch = ZohoSyncBatch::query()->findOrFail($batchId);
        $retry = ['attempted' => 0, 'resolved' => 0];
        $inventoryModules = 0;
        if ($batch->mode === 'reconcile') {
            $retry = $this->failures->retry(null, max(1, (int) config('zoho-v2.retry.batch_size', 100)));
            $inventory = $this->inventory->inventory();
            if (! $inventory->complete) {
                throw new RuntimeException('Zoho inventory did not complete; retry is safe.');
            }
            $inventoryModules = $inventory->discoveredModules;
        }
        $users = $this->identities->autoMapUsers();
        $contacts = $this->identities->linkMarketingContacts();

        return [
            'batch_id' => $batchId,
            'failures_attempted' => max(0, (int) ($retry['attempted'] ?? 0)),
            'failures_resolved' => max(0, (int) ($retry['resolved'] ?? 0)),
            'inventory_modules' => max(0, $inventoryModules),
            'users_mapped' => max(0, (int) ($users['mapped'] ?? 0)),
            'user_matches_ambiguous' => max(0, (int) ($users['ambiguous'] ?? 0)),
            'contact_links_created' => max(0, (int) ($contacts['created'] ?? 0)),
            'contact_matches_ambiguous' => max(0, (int) ($contacts['ambiguous'] ?? 0)),
        ];
    }
}
