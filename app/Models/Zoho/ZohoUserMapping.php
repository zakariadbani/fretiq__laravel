<?php

namespace App\Models\Zoho;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoUserMapping extends Model
{
    protected $table = 'zoho_user_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_confirmed' => 'boolean', 'is_override' => 'boolean', 'confirmed_at' => 'datetime', 'audit_metadata' => 'array'];
    }

    public function fretiqUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fretiq_user_id');
    }
}
