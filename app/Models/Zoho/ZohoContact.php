<?php

namespace App\Models\Zoho;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoContact extends ZohoMirror
{
    protected $table = 'zoho_contacts';

    public function fretiqContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'fretiq_contact_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ZohoAccount::class, 'account_zoho_id', 'zoho_id');
    }
}
