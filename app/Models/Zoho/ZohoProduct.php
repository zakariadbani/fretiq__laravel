<?php

namespace App\Models\Zoho;

class ZohoProduct extends ZohoMirror
{
    protected $table = 'zoho_products';

    protected function casts(): array
    {
        return parent::casts() + ['unit_price' => 'decimal:2'];
    }
}
