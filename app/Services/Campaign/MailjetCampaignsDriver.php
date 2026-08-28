<?php

namespace App\Services\Campaign;

use App\Mail\CampaignMailable;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Support\ApiLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * MailjetCampaignsDriver — CampaignsClient implementation backed by the Mailjet
 * Send API v3.1, per-recipient (same shape as SmtpCampaignsDriver, not Mailjet's
 * list/campaign API).
 *
 * Bounce/open/click feedback is pulled separately by SyncMailjetEventsJob via
 * GET /v3/REST/message — that endpoint's field shapes are UNVERIFIED live; see
 * that job's docblock before relying on it in production.
 *
 * @see campaign-automation.md §7 (driver boundary)
 */
class MailjetCampaignsDriver implements CampaignsClient
{
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

        $identity = $campaign->senderIdentity;

        $mailable = new CampaignMailable(
            campaign: $campaign,
            template: $campaign->template,
            contact: $recipient->contact,
            subjectLine: $subject,
            trackingToken: $trackingToken,
            unsubscribeUrl: $unsubscribeUrl,
            resolvedHtml: $resolved['html_content'],
            language: $resolved['language'],
            messageId: 'campaign-recipient-' . $recipient->id . '@fretiq.local',
        );

        $html = $mailable->render();

        $oneClickUnsubscribeUrl = URL::signedRoute('unsubscribe.one-click', ['contact' => $recipient->contact->id]);

        $response = Http::withBasicAuth(
            (string) config('services.mailjet.key'),
            (string) config('services.mailjet.secret'),
        )
            ->timeout(30)
            ->post(rtrim((string) config('services.mailjet.api_url'), '/') . '/v3.1/send', [
                'SandboxMode' => (bool) config('services.mailjet.sandbox', false),
                'Messages' => [[
                    'From' => [
                        'Email' => $identity->email,
                        'Name' => $identity->name,
                    ],
                    'To' => [[
                        'Email' => $recipient->contact->email,
                    ]],
                    'Subject' => $subject,
                    'HTMLPart' => $html,
                    'CustomID' => 'campaign-recipient-' . $recipient->id,
                    'CustomCampaign' => 'fretiq-run-' . $run->id,
                    'DeduplicateCampaign' => false,
                    'Headers' => [
                        'List-Unsubscribe' => '<' . $oneClickUnsubscribeUrl . '>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ],
                ]],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                '[MailjetCampaignsDriver] send échoué (HTTP ' . $response->status() . '): ' . ApiLog::excerpt($response->body(), 300)
            );
        }

        $payload = $response->json() ?? [];
        $message = $payload['Messages'][0] ?? null;
        if (! is_array($message) || ($message['Status'] ?? null) !== 'success') {
            throw new \RuntimeException(
                '[MailjetCampaignsDriver] send erreur API Mailjet : ' . ApiLog::excerpt($response->body(), 300)
            );
        }

        $messageId = (string) ($message['To'][0]['MessageID'] ?? '');
        if ($messageId === '') {
            throw new \RuntimeException('[MailjetCampaignsDriver] send : MessageID absent de la réponse Mailjet.');
        }

        return $messageId;
    }

    public function driverName(): string
    {
        return 'mailjet';
    }

    public function supportsBounceFeedback(Campaign $campaign): bool
    {
        return true;
    }
}
