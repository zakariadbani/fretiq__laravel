<?php

namespace App\Mail;

use App\Models\Contact;
use App\Models\SenderIdentity;
use App\Models\SequenceStep;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * SequenceStepMailable — real Mailable for drip sequence step emails.
 *
 * Mirrors CampaignMailable in structure and compliance requirements:
 *   - NEVER Mail::raw() — this class is the only allowed send path for sequences.
 *   - Embeds the tracking pixel (1×1 GIF via APP_URL/track/open/{token}).
 *   - Adds RFC 8058 List-Unsubscribe + List-Unsubscribe-Post headers.
 *   - Variable substitution: {{contact.name}}, {{contact.email}}, {{company.name}},
 *     {{unsubscribe_url}}.
 *   - From address falls back to the app default (config mail.from) when the
 *     sequence step has no sender identity; override in the service layer when needed.
 *
 * @see CampaignMailable  For the campaign equivalent.
 * @see compliance-deliverability.md §3
 */
class SequenceStepMailable extends Mailable
{
    public function __construct(
        private readonly SequenceStep    $step,
        private readonly Contact         $contact,
        private readonly string          $subjectLine,
        private readonly string          $trackingToken,
        private readonly string          $unsubscribeUrl,
        private readonly ?SenderIdentity $senderIdentity = null,
    ) {}

    /**
     * Build the message envelope (subject + from address).
     *
     * When a SenderIdentity is provided (via the originating campaign), its
     * `email` and `name` columns are used as the From address. Otherwise the
     * default `mail.from` configured in config/mail.php is used as fallback.
     */
    public function envelope(): Envelope
    {
        if ($this->senderIdentity !== null) {
            return new Envelope(
                from: new Address(
                    $this->senderIdentity->email,
                    $this->senderIdentity->name ?? '',
                ),
                subject: $this->subjectLine,
            );
        }

        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    /**
     * Build the message content from the step's template HTML.
     *
     * Variable substitution + tracking pixel + unsubscribe footer are applied
     * in renderHtml(), mirroring the CampaignMailable approach.
     */
    public function content(): Content
    {
        return new Content(htmlString: $this->renderHtml());
    }

    /**
     * RFC 8058 List-Unsubscribe headers — required by compliance spec.
     */
    public function headers(): Headers
    {
        return new Headers(
            text: [
                'List-Unsubscribe'      => '<' . $this->unsubscribeUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Render the step template HTML with variable substitution, tracking pixel,
     * and the unsubscribe footer block.
     */
    private function renderHtml(): string
    {
        $contact = $this->contact;
        $company = $contact->company;
        $template = $this->step->template;

        $replacements = [
            '{{contact.name}}'    => e($contact->name ?? ''),
            '{{contact.email}}'   => e($contact->email ?? ''),
            '{{company.name}}'    => e($company?->name ?? ''),
            '{{unsubscribe_url}}' => $this->unsubscribeUrl,
        ];

        $html = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $template->html_content ?? '',
        );

        // Tracking pixel — 1×1 transparent GIF loaded via APP_URL/track/open/{token}
        $pixelUrl = rtrim(config('app.url'), '/') . '/track/open/' . $this->trackingToken;
        $pixel    = '<img src="' . e($pixelUrl) . '" width="1" height="1" alt="" '
                  . 'style="display:none;width:1px;height:1px;" />';

        // Unsubscribe footer (matches CampaignMailable wording)
        $unsubscribeBlock = '<div style="margin-top:24px;font-size:11px;color:#888;font-family:sans-serif;">'
            . 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels. '
            . '<a href="' . $this->unsubscribeUrl . '" style="color:#888;">Se désabonner</a>'
            . '</div>';

        return $html . "\n" . $pixel . "\n" . $unsubscribeBlock;
    }
}
