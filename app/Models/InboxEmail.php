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
        'triage_action',
        'contact_id',
        'sequence_step_send_id',
        'campaign_recipient_id',
        'demande_id',
        'triaged_by',
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

    public function sequenceStepSend(): BelongsTo
    {
        return $this->belongsTo(SequenceStepSend::class);
    }

    public function demande(): BelongsTo
    {
        return $this->belongsTo(Demande::class);
    }

    public function triagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triaged_by');
    }
}
