<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Contact;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * CampaignMailable — the ONLY way to send campaign emails in fretiq.
 *
 * Hard rules (compliance-deliverability.md §3):
 *   - NEVER use Mail::raw() — this Mailable class is the required path.
 *   - Always embeds the tracking pixel (1x1 GIF via APP_URL/track/open/{token}).
 *   - Always adds List-Unsubscribe + List-Unsubscribe-Post headers (RFC 8058).
 *   - From address always comes from the campaign's SenderIdentity.
 */
class CampaignMailable extends Mailable
{
    public function __construct(
        private readonly Campaign         $campaign,
        private readonly CampaignTemplate $template,
        private readonly Contact          $contact,
        private readonly string           $subjectLine,
        private readonly string           $trackingToken,
        private readonly string           $unsubscribeUrl,
    ) {}

    /**
     * Build the message envelope (subject, from, custom headers).
     */
    public function envelope(): Envelope
    {
        $identity = $this->campaign->senderIdentity;

        return new Envelope(
            from: new Address($identity->email, $identity->name),
            subject: $this->subjectLine,
        );
    }

    /**
     * Build the message content using inline HTML (avoids blade view dependency).
     *
     * Variable substitution supports:
     *   {{contact.name}}, {{contact.email}}, {{company.name}}, {{unsubscribe_url}}
     *
     * After substitution, the tracking pixel and the unsubscribe footer are appended.
     */
    public function content(): Content
    {
        $html = $this->renderHtml();

        return new Content(htmlString: $html);
    }

    /**
     * Add RFC 8058 List-Unsubscribe headers required by compliance spec.
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

    // ── Public helpers ─────────────────────────────────────────────────────────

    /**
     * Replace merge tags in any text string for a given contact.
     *
     * Supported tokens (identical set for both subject and body):
     *   {{contact.name}}, {{contact.email}}, {{company.name}}, {{unsubscribe_url}}
     *
     * Values are HTML-escaped so the result is safe to embed directly in HTML.
     * For the subject line (plain text) the e() escaping is a no-op for normal
     * names and is the safest default — callers that need raw text can strip_tags
     * afterwards if required.
     */
    public static function renderMergeTags(
        string  $text,
        Contact $contact,
        string  $unsubscribeUrl,
    ): string {
        $company = $contact->company;

        $replacements = [
            '{{contact.name}}'    => e($contact->name ?? ''),
            '{{contact.email}}'   => e($contact->email ?? ''),
            '{{company.name}}'    => e($company?->name ?? ''),
            '{{unsubscribe_url}}' => $unsubscribeUrl,
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $text,
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Perform variable substitution on the template HTML, then append
     * the tracking pixel and the unsubscribe link block.
     */
    private function renderHtml(): string
    {
        $html = self::renderMergeTags(
            $this->template->html_content ?? '',
            $this->contact,
            $this->unsubscribeUrl,
        );

        // Tracking pixel — 1×1 transparent GIF loaded via APP_URL/track/open/{token}
        $pixelUrl = rtrim(config('app.url'), '/') . '/track/open/' . $this->trackingToken;
        $pixel    = '<img src="' . e($pixelUrl) . '" width="1" height="1" alt="" '
                  . 'style="display:none;width:1px;height:1px;" />';

        // Unsubscribe footer (plain link — keeps it minimal and deliverable)
        $unsubscribeBlock = '<div style="margin-top:24px;font-size:11px;color:#888;font-family:sans-serif;">'
            . 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels. '
            . '<a href="' . $this->unsubscribeUrl . '" style="color:#888;">Se désabonner</a>'
            . '</div>';

        return $html . "\n" . $pixel . "\n" . $unsubscribeBlock;
    }
}
