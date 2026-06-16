<?php

namespace App\Mail\Concerns;

use App\Models\Contact;

/**
 * RendersTrackedHtml — shared behaviour for campaign and sequence-step Mailables.
 *
 * Provides:
 *  - renderMergeTags()              — replace {{contact.*}} / {{company.*}} tokens
 *  - appendTrackingPixelAndFooter() — append the 1×1 pixel + language-aware footer
 *
 * Both methods are extracted verbatim from CampaignMailable / SequenceStepMailable
 * to eliminate the duplication. Output is byte-identical to the pre-refactor code.
 */
trait RendersTrackedHtml
{
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

    /**
     * Append the tracking pixel and language-aware unsubscribe footer to $html.
     *
     * Tracking pixel — 1×1 transparent GIF loaded via APP_URL/track/open/{token}
     * Footer          — language-aware (config/translation.php §footer); falls back
     *                   to base language then to a hardcoded FR default.
     *
     * The unsubscribe href is intentionally UN-escaped (matching original behaviour);
     * intro and link_label are e()-escaped.
     */
    private function appendTrackingPixelAndFooter(
        string $html,
        string $trackingToken,
        string $unsubscribeUrl,
        string $language,
    ): string {
        // Tracking pixel — 1×1 transparent GIF loaded via APP_URL/track/open/{token}
        $pixelUrl = rtrim(config('app.url'), '/') . '/track/open/' . $trackingToken;
        $pixel    = '<img src="' . e($pixelUrl) . '" width="1" height="1" alt="" '
                  . 'style="display:none;width:1px;height:1px;" />';

        // Language-aware unsubscribe footer (config/translation.php §footer).
        // Null-safe: if the config is missing or the language key is absent,
        // fall back to the base language, then to a hardcoded FR default.
        $footers = config('translation.footer') ?? [];
        $base    = config('translation.base_language', 'fr');
        $footer  = $footers[$language]
                ?? $footers[$base]
                ?? ['intro' => 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels.', 'link_label' => 'Se désabonner'];
        $unsubscribeBlock = '<div style="margin-top:24px;font-size:11px;color:#888;font-family:sans-serif;">'
            . e($footer['intro']) . ' '
            . '<a href="' . $unsubscribeUrl . '" style="color:#888;">' . e($footer['link_label']) . '</a>'
            . '</div>';

        return $html . "\n" . $pixel . "\n" . $unsubscribeBlock;
    }
}
