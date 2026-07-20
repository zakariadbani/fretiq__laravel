<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignCompanyDispatch extends Model
{
    protected $fillable = [
        'campaign_id',
        'company_id',
        'current_run_id',
        'status',
        'attempts',
        'last_error',
        'claimed_at',
        'processed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'current_run_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'company_dispatch_id');
    }
}
