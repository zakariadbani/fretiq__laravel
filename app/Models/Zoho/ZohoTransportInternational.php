<?php

namespace App\Models\Zoho;

class ZohoTransportInternational extends ZohoMirror
{
    protected $table = 'zoho_transport_international';

    protected function casts(): array
    {
        return parent::casts() + ['exchange_rate' => 'decimal:6'];
    }
}
