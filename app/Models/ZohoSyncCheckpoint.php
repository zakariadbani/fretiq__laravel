<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoSyncCheckpoint extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'zoho_sync_checkpoints';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'module',
        'cursor_modified_time',
        'cursor_page_token',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cursor_modified_time' => 'datetime',
    ];
}
