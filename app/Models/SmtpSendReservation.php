<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmtpSendReservation extends Model
{
    public const SOURCE_CAMPAIGN_RECIPIENT = 'campaign_recipient';
    public const SOURCE_SEQUENCE_STEP_SEND = 'sequence_step_send';

    // Accepted is durable remote acceptance awaiting local reconciliation.
    // Uncertain may have been accepted by a remote SMTP server and is never retried.
    public const ACTIVE_STATUSES = ['reserved', 'sending', 'accepted', 'sent', 'uncertain'];

    protected $fillable = [
        'sender_identity_id',
        'campaign_id',
        'source_type',
        'source_id',
        'reserved_for',
        'status',
        'attempted_at',
        'sent_at',
        'accepted_at',
        'lease_expires_at',
        'attempt_count',
        'provider_message_id',
    ];

    protected $casts = [
        'reserved_for' => 'datetime',
        'attempted_at' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
