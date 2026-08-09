<?php

namespace App\Models\Zoho;

use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZohoAccount extends ZohoMirror
{
    protected $table = 'zoho_accounts';

    public function fretiqCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'fretiq_company_id');
    }

    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_zoho_id', 'zoho_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ZohoContact::class, 'account_zoho_id', 'zoho_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(ZohoDeal::class, 'account_zoho_id', 'zoho_id');
    }
}
