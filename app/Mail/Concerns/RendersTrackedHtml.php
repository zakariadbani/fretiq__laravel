<?php

namespace App\Mail\Concerns;

use App\Models\Contact;
use App\Services\Campaign\UnsubscribeHtmlNormalizer;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Shared merge-tag, tracking-pixel, and template-owned unsubscribe rendering for campaign mailables.
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
     * Normalize template-owned unsubscribe links, then append the tracking pixel.
     */
    private function appendTrackingPixel(
        string $html,
        string $trackingToken,
        string $unsubscribeUrl,
    ): string {
        $pixelUrl = rtrim(config('app.url'), '/') . '/track/open/' . $trackingToken;
        $pixel = '<img src="' . e($pixelUrl) . '" width="1" height="1" alt="" '
            . 'style="display:none;width:1px;height:1px;" />';

        [$html] = UnsubscribeHtmlNormalizer::normalize($html, $unsubscribeUrl);
        $html = $this->rewriteLinksForClickTracking($html, $trackingToken, $unsubscribeUrl);

        return UnsubscribeHtmlNormalizer::insertBeforeDocumentEnd(
            $html,
            $pixel,
        );
    }

    /**
     * Wrap every http(s) anchor href through the click-tracking redirect.
     *
     * Skipped by construction (never matched by the inner pattern below):
     *   - mailto:, tel:, #fragment, and any other non-http(s) scheme.
     *   - non-<a> tags (e.g. <link rel="stylesheet">), since only <a> tags
     *     are scanned by the outer pattern.
     * Explicitly skipped: the unsubscribe URL (exact match, so an explicit
     * template-owned link is never rerouted through it).
     *
     * <script>/<style>/comment regions are protected (sentinel-swapped) via
     * UnsubscribeHtmlNormalizer before the regex scan, same as normalize()
     * — a raw scan over a large/unclosed such region can otherwise exhaust
     * pcre.backtrack_limit on a pathological template.
     */
    private function rewriteLinksForClickTracking(string $html, string $trackingToken, string $unsubscribeUrl): string
    {
        [$protectedHtml, $nonRenderedSections] = UnsubscribeHtmlNormalizer::protectNonRenderedSections($html, '');

        $attribute = '(?:[^>"\']|"[^"]*"|\'[^\']*\')';
        $openTagPattern = '~<a\b' . $attribute . '*>~i';

        $rewritten = preg_replace_callback(
            $openTagPattern,
            function (array $match) use ($trackingToken, $unsubscribeUrl): string {
                $tag = preg_replace_callback(
                    '~\bhref\s*=\s*(["\'])(https?://.*?)\1~i',
                    function (array $href) use ($trackingToken, $unsubscribeUrl): string {
                        $destination = html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5);

                        if ($destination === $unsubscribeUrl) {
                            return $href[0];
                        }

                        // Relative (host-agnostic) signature — must match the
                        // 'signed:relative' route middleware in routes/web.php,
                        // and prepended with the app URL so the link works from
                        // an email client (a relative href has nothing to
                        // resolve against there).
                        $clickPath = URL::signedRoute('track.click', [
                            'token' => $trackingToken,
                            'url'   => $destination,
                        ], null, false);
                        $clickUrl = rtrim(config('app.url'), '/') . $clickPath;

                        return 'href=' . $href[1] . e($clickUrl) . $href[1];
                    },
                    $match[0],
                    1,
                );

                return $tag ?? $match[0];
            },
            $protectedHtml,
        );

        return strtr($rewritten ?? $protectedHtml, $nonRenderedSections);
    }
}
