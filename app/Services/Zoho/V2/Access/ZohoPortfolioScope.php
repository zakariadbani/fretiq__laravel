<?php

namespace App\Services\Zoho\V2\Access;

use App\Models\User;
use App\Models\Zoho\ZohoUserMapping;
use Illuminate\Database\Eloquent\Builder;

/** Applies the single, shared CRM visibility rule to every explorer query. */
final class ZohoPortfolioScope
{
    public function apply(Builder $query, ?User $user): ZohoPortfolioScopeResult
    {
        if (! $user) {
            return new ZohoPortfolioScopeResult($query->whereRaw('1 = 0'));
        }

        if ($user->hasAnyRole(['admin', 'superadmin'])) {
            return new ZohoPortfolioScopeResult($query);
        }

        if (! $user->hasRole('commercial')) {
            return new ZohoPortfolioScopeResult($query->whereRaw('1 = 0'));
        }

        $mappings = ZohoUserMapping::query()
            ->where('fretiq_user_id', $user->getKey())
            ->where('is_confirmed', true)
            ->whereNotNull('zoho_user_id')
            ->limit(2)
            ->get(['zoho_user_id']);

        if ($mappings->count() !== 1) {
            return new ZohoPortfolioScopeResult($query->whereRaw('1 = 0'), true);
        }

        $zohoUserId = (string) $mappings->first()->zoho_user_id;

        return new ZohoPortfolioScopeResult($query->where('owner_zoho_id', $zohoUserId), false, $zohoUserId);
    }
}
