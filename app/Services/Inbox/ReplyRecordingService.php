<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\InboxEmail;
use App\Models\SequenceStepSend;
use App\Services\Campaign\SequenceService;

/** Shared reply evidence write for automatic matching and manual triage. */
final class ReplyRecordingService
{
    public function __construct(private readonly SequenceService $sequences) {}

    public function record(InboxEmail $email): void
    {
        if ($email->contact_id === null) {
            return;
        }

        $contact = Contact::query()->find($email->contact_id);
        if ($contact === null) {
            return;
        }

        $recipient = $email->campaignRecipient;
        if ($recipient === null && $email->sequenceStepSend?->campaign_run_id !== null) {
            $recipient = CampaignRecipient::query()
                ->where('campaign_run_id', $email->sequenceStepSend->campaign_run_id)
                ->where('contact_id', $contact->id)->first();
        }
        $this->recordContact($contact, $recipient, $email->sequenceStepSend);
    }

    public function recordContact(
        Contact $contact,
        ?CampaignRecipient $recipient = null,
        ?SequenceStepSend $sequenceSend = null,
    ): void {
        if ($recipient !== null && $recipient->status !== 'replied') {
            $recipient->update(['status' => 'replied', 'replied_at' => now()]);
        }

        if ($sequenceSend !== null) {
            SequenceStepSend::query()->whereKey($sequenceSend->id)
                ->where('status', '!=', 'replied')->update(['status' => 'replied']);
        }

        $this->sequences->stopForReply($contact);
    }
}
