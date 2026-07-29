<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxEmail extends Model
{
    public const STATUS_NOUVEAU = 'nouveau';
    public const STATUS_TRAITE = 'traite';
    public const STATUS_IGNORE = 'ignore';

    protected $fillable = [
        'sender_identity_id',
        'message_id',
        'in_reply_to',
        'from_email',
        'from_name',
        'to_email',
        'subject',
        'body_text',
        'body_html',
        'status',
        'contact_id',
        'campaign_recipient_id',
        'processed_at',
        'received_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function getName(): string
    {
        return 'inbox_email';
    }

    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }
}
