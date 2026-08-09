<?php

namespace App\Services\Zoho\V2\Mappers;

use InvalidArgumentException;

class ZohoMapperResolver
{
    public function forModule(string $module): ZohoRecordMapper
    {
        $normalized = strtolower(trim($module));
        $normalized = str_replace(['-', ' '], '_', $normalized);

        return match ($normalized) {
            'users' => new UserMapper, 'leads' => new LeadMapper, 'accounts' => new AccountMapper, 'contacts' => new ContactMapper,
            'deals' => new DealMapper, 'quotes' => new QuoteMapper, 'products' => new ProductMapper, 'quoted_items', 'quoteditems' => new QuoteItemMapper,
            'tasks', 'task', 'activities', 'activity' => new ActivityMapper('task'), 'events', 'event', 'meetings', 'meeting' => new ActivityMapper('meeting'), 'calls', 'call' => new ActivityMapper('call'), 'notes', 'note' => new ActivityMapper('note'),
            'dealhistory', 'deal_history' => new DealStageHistoryMapper, 'actions_commercials', 'actionscommercials' => new ActionsCommercialsMapper, 'transport_international', 'transportinternational' => new TransportInternationalMapper,
            default => throw new InvalidArgumentException("No V2 mapper is registered for Zoho module [{$module}]."),
        };
    }
}
