<?php

namespace App\Models\Zoho\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HasZohoMirrorState
{
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('zoho_deleted_at');
    }

    public function scopeTombstoned(Builder $query): Builder
    {
        return $query->whereNotNull('zoho_deleted_at');
    }
}
