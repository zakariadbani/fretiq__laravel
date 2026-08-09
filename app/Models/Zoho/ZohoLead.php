<?php

namespace App\Models\Zoho;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoLead extends ZohoMirror
{
    protected $table = 'zoho_leads';

    protected function casts(): array
    {
        return parent::casts() + ['is_converted' => 'boolean'];
    }

    public function fretiqCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'fretiq_company_id');
    }

    public function fretiqContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'fretiq_contact_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ZohoAccount::class, 'account_zoho_id', 'zoho_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ZohoContact::class, 'contact_zoho_id', 'zoho_id');
    }
}
