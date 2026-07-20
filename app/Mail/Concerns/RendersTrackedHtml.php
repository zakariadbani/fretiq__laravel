<?php

namespace App\Mail\Concerns;

use App\Models\Contact;
use App\Services\Campaign\UnsubscribeHtmlNormalizer;
use Illuminate\Support\Str;

/**
 * Shared merge-tag, tracking-pixel, and unsubscribe rendering for campaign mailables.
 */
trait RendersTrackedHtml
{
    /**
     * Replace merge tags in any text string for a given contact.
     *
     * Supported tokens:
     *   {{contact.name}}, {{contact.first_name}}, {{contact.email}},
     *   {{company.name}}, {{company.sector}}, {{unsubscribe_url}}
     */
    public static function renderMergeTags(
        string  $text,
        Contact $contact,
        string  $unsubscribeUrl,
    ): string {
        $company = $contact->company;

        $replacements = [
            '{{contact.name}}'       => e($contact->name ?? ''),
            '{{contact.first_name}}' => e(Str::of($contact->name ?? '')->squish()->before(' ')->toString()),
            '{{contact.email}}'      => e($contact->email ?? ''),
            '{{company.name}}'       => e($company?->name ?? ''),
            '{{company.sector}}'     => e($company?->sector ?? ''),
            '{{unsubscribe_url}}'    => $unsubscribeUrl,
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $text,
        );
    }

    /**
     * Append the tracking pixel and one language-aware unsubscribe link.
     */
    private function appendTrackingPixelAndFooter(
        string $html,
        string $trackingToken,
        string $unsubscribeUrl,
        string $language,
    ): string {
        $pixelUrl = rtrim(config('app.url'), '/') . '/track/open/' . $trackingToken;
        $pixel = '<img src="' . e($pixelUrl) . '" width="1" height="1" alt="" '
            . 'style="display:none;width:1px;height:1px;" />';

        $footers = config('translation.footer') ?? [];
        $base = config('translation.base_language', 'fr');
        $footer = $footers[$language]
            ?? $footers[$base]
            ?? [
                'intro' => 'Vous recevez cet email car vous faites partie de notre liste de contacts professionnels.',
                'link_label' => 'Se désabonner',
            ];

        [$html, $hasUnsubscribeLink] = UnsubscribeHtmlNormalizer::normalize($html, $unsubscribeUrl);
        $unsubscribeBlock = '';
        if (! $hasUnsubscribeLink) {
            $unsubscribeBlock = "\n" . '<div style="margin-top:24px;font-size:11px;color:#888;font-family:sans-serif;">'
                . e($footer['intro']) . ' '
                . '<a href="' . $unsubscribeUrl . '" style="color:#888;">' . e($footer['link_label']) . '</a>'
                . '</div>';
        }

        return UnsubscribeHtmlNormalizer::insertBeforeDocumentEnd(
            $html,
            $pixel . $unsubscribeBlock,
        );
    }
}
