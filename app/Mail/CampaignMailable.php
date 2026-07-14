<?php

namespace App\Mail;

use App\Mail\Concerns\RendersTrackedHtml;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Contact;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

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
    use RendersTrackedHtml;

    public function __construct(
        private readonly Campaign         $campaign,
        private readonly CampaignTemplate $template,
        private readonly Contact          $contact,
        private readonly string           $subjectLine,
        private readonly string           $trackingToken,
        private readonly string           $unsubscribeUrl,
        private readonly string           $resolvedHtml = '',
        private readonly string           $language = 'fr',
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
                'List-Unsubscribe'      => '<' . URL::signedRoute('unsubscribe.one-click', ['contact' => $this->contact->id]) . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Perform variable substitution on the template HTML, then append
     * the tracking pixel and the unsubscribe link block.
     */
    private function renderHtml(): string
    {
        $source = $this->resolvedHtml !== '' ? $this->resolvedHtml : ($this->template->html_content ?? '');
        $html   = self::renderMergeTags($source, $this->contact, $this->unsubscribeUrl);

        return $this->appendTrackingPixelAndFooter($html, $this->trackingToken, $this->unsubscribeUrl, $this->language);
    }
}
