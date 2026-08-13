<?php

namespace App\Services\Campaign;

use App\Mail\CampaignMailable;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SenderIdentity;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepSend;
use App\Services\Mail\SmtpMailRouter;

class SmtpCampaignsDriver implements CampaignsClient
{
    public function __construct(private readonly SmtpMailRouter $mailer)
    {
    }

    public function send(
        CampaignRecipient $recipient,
        Campaign $campaign,
        CampaignRun $run,
        string $trackingToken,
        string $unsubscribeUrl,
    ): string {
        $recipient->loadMissing('contact.company');
        $campaign->loadMissing(['template.translations', 'senderIdentity']);
        $resolved = $campaign->template->resolveFor($recipient->contact->company?->country);
        $rawSubject = $campaign->subject ?: $resolved['subject'];
        $subject = CampaignMailable::renderMergeTags($rawSubject, $recipient->contact, $unsubscribeUrl);

        $this->mailer->send(
            $campaign->senderIdentity,
            $recipient->contact->email,
            new CampaignMailable(
                campaign: $campaign,
                template: $campaign->template,
                contact: $recipient->contact,
                subjectLine: $subject,
                trackingToken: $trackingToken,
                unsubscribeUrl: $unsubscribeUrl,
                resolvedHtml: $resolved['html_content'],
                language: $resolved['language'],
                messageId: 'campaign-recipient-' . $recipient->id . '@fretiq.local',
            ),
        );

        return 'smtp-recipient-' . $recipient->id;
    }

    public function sendSequence(
        SequenceStepSend $stepSend,
        SequenceEnrollment $enrollment,
        SequenceStep $step,
        SenderIdentity $identity,
        string $trackingToken,
        string $unsubscribeUrl,
    ): string {
        $enrollment->loadMissing('contact.company');
        $step->loadMissing('template.translations');
        $resolved = $step->template->resolveFor($enrollment->contact->company?->country);
        $subject = $step->subject ?: $resolved['subject'];

        $this->mailer->send(
            $identity,
            $enrollment->contact->email,
            new SequenceStepMailable(
                step: $step,
                contact: $enrollment->contact,
                subjectLine: $subject,
                trackingToken: $trackingToken,
                unsubscribeUrl: $unsubscribeUrl,
                senderIdentity: $identity,
                resolvedHtml: $resolved['html_content'],
                language: $resolved['language'],
                messageId: 'sequence-send-' . $stepSend->id . '@fretiq.local',
            ),
        );

        return 'smtp-sequence-' . $stepSend->id;
    }

    public function driverName(): string
    {
        return 'smtp';
    }

    public function supportsBounceFeedback(Campaign $campaign): bool
    {
        $campaign->loadMissing('senderIdentity');

        return $campaign->senderIdentity?->hasHealthyBounceFeedback() ?? false;
    }
}
