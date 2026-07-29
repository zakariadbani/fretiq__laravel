<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\InboxEmail;
use App\Models\SequenceStepSend;

class ReplyMatchingService
{
    public function match(InboxEmail $email, ?string $references = null): void
    {
        $thread = trim(($email->in_reply_to ?? '') . ' ' . ($references ?? ''));
        preg_match_all('/(campaign-recipient|sequence-send)-(\d+)@fretiq\.local/i', $thread, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (strtolower($match[1]) === 'campaign-recipient') {
                $recipient = CampaignRecipient::with('contact')->find((int) $match[2]);
                if ($recipient !== null && $this->matchesSender($recipient->contact, $email->from_email)) {
                    $email->update([
                        'campaign_recipient_id' => $recipient->id,
                        'contact_id' => $recipient->contact_id,
                    ]);
                    return;
                }
            } else {
                $send = SequenceStepSend::with('enrollment.contact')->find((int) $match[2]);
                $contact = $send?->enrollment?->contact;
                if ($contact !== null && $this->matchesSender($contact, $email->from_email)) {
                    $email->update(['contact_id' => $send->enrollment->contact_id]);
                    return;
                }
            }
        }

        $contact = Contact::whereRaw('LOWER(email) = ?', [mb_strtolower($email->from_email)])->first();
        if ($contact !== null) {
            $email->update(['contact_id' => $contact->id]);
        }
    }

    private function matchesSender(Contact $contact, string $fromEmail): bool
    {
        return strcasecmp(trim($contact->email), trim($fromEmail)) === 0;
    }
}
