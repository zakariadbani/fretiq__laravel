<?php

namespace App\Services\Campaign;

final class UnsubscribeHtmlNormalizer
{
    /**
     * Keep at most one clickable target link and remove every stray occurrence.
     *
     * @return array{0: string, 1: bool, 2: bool} Normalized HTML, whether a link was
     *                                             preserved, and whether normalization
     *                                             hit a PCRE failure (backtrack/JIT stack
     *                                             limit) while scanning. Callers that only
     *                                             need the first two elements can keep
     *                                             destructuring `[$html, $preserved] = ...`
     *                                             — PHP list-destructuring of fewer elements
     *                                             than the array size is safe.
     */
    public static function normalize(string $html, string $target): array
    {
        [$html, $nonRenderedSections] = self::protectNonRenderedSections($html, $target);
        $quotedTarget = preg_quote($target, '~');
        $attribute = '(?:[^>"\']|"[^"]*"|\'[^\']*\')';
        $anchorPattern = '~<a\b(?=' . $attribute . '*\bhref\s*=\s*(?:(["\'])'
            . $quotedTarget . '\1|' . $quotedTarget . '(?=\s|>)))'
            . $attribute . '*>(.*?)</a\s*>~is';
        $sentinel = '__FRETIQ_UNSUBSCRIBE_HREF_' . hash('sha256', $target) . '__';

        while (str_contains($html, $sentinel)) {
            $sentinel .= '_';
        }

        $preserved = false;
        $normalizationFailed = false;
        $normalized = preg_replace_callback(
            $anchorPattern,
            function (array $match) use (&$preserved, &$normalizationFailed, $quotedTarget, $sentinel, $target): string {
                if ($preserved) {
                    return $match[2];
                }

                $preserved = true;
                $anchor = preg_replace_callback(
                    '~(\bhref\s*=\s*)(?:(["\'])' . $quotedTarget . '\2|'
                        . $quotedTarget . '(?=\s|>))~i',
                    static function (array $href) use ($sentinel): string {
                        $quote = $href[2] ?? '';

                        return $href[1] . $quote . $sentinel . $quote;
                    },
                    $match[0],
                    1,
                );

                if ($anchor === null) {
                    $normalizationFailed = true;

                    return $match[0];
                }

                return str_replace($target, '', $anchor);
            },
            $html,
        );

        if ($normalized === null || $normalizationFailed) {
            return [strtr(str_replace($target, '', $html), $nonRenderedSections), false, true];
        }

        $normalized = str_replace($target, '', $normalized);
        if ($preserved) {
            $normalized = str_replace($sentinel, $target, $normalized);
        }

        return [strtr($normalized, $nonRenderedSections), $preserved, false];
    }

    public static function insertBeforeDocumentEnd(string $html, string $addition): string
    {
        $maskedHtml = self::maskNonRenderedSections($html);

        foreach (['body', 'html'] as $tag) {
            $offset = self::findLastClosingTag($maskedHtml, $tag);
            if ($offset !== null) {
                return substr($html, 0, $offset) . $addition . "\n" . substr($html, $offset);
            }
        }

        return $html . "\n" . $addition;
    }

    /**
     * Replace every <script>/<style>/comment region with a short sentinel so
     * a subsequent regex scan never walks their raw (possibly huge,
     * possibly pathological) content — that's what keeps normalize() safe
     * under a tiny pcre.backtrack_limit. Reused by RendersTrackedHtml's
     * click-link rewriter for the same reason. Pass an empty $target to
     * protect without stripping anything (str_replace('', '', $x) === $x).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function protectNonRenderedSections(string $html, string $target): array
    {
        $sections = [];
        $baseSentinel = '__FRETIQ_NON_RENDERED_' . hash('sha256', $target) . '_';
        $protected = '';
        $cursor = 0;

        foreach (self::scanNonRenderedSections($html) as $region) {
            $protected .= substr($html, $cursor, $region['start'] - $cursor);
            $sentinel = $baseSentinel . count($sections) . '__';
            while (str_contains($html, $sentinel) || array_key_exists($sentinel, $sections)) {
                $sentinel .= '_';
            }

            $section = str_replace(
                $target,
                '',
                substr($html, $region['start'], $region['length']),
            );
            if (! $region['closed']) {
                $section .= $region['kind'] === 'comment' ? '-->' : '</' . $region['kind'] . '>';
            }

            $sections[$sentinel] = $section;
            $protected .= $sentinel;
            $cursor = $region['start'] + $region['length'];
        }

        return [$protected . substr($html, $cursor), $sections];
    }

    private static function maskNonRenderedSections(string $html): string
    {
        $masked = '';
        $cursor = 0;

        foreach (self::scanNonRenderedSections($html) as $region) {
            $masked .= substr($html, $cursor, $region['start'] - $cursor);
            $masked .= str_repeat(' ', $region['length']);
            $cursor = $region['start'] + $region['length'];
        }

        return $masked . substr($html, $cursor);
    }

    /** @return array<int, array{start: int, length: int, kind: string, closed: bool}> */
    private static function scanNonRenderedSections(string $html): array
    {
        $regions = [];
        $offset = 0;
        $htmlLength = strlen($html);

        while ($offset < $htmlLength) {
            $candidates = array_filter([
                'comment' => strpos($html, '<!--', $offset),
                'script' => self::findTagOpening($html, 'script', $offset),
                'style' => self::findTagOpening($html, 'style', $offset),
            ], static fn ($position): bool => $position !== false);

            if ($candidates === []) {
                break;
            }

            $start = min($candidates);
            $kind = array_search($start, $candidates, true);
            $end = false;

            if ($kind === 'comment') {
                $closing = strpos($html, '-->', $start + 4);
                $end = $closing === false ? false : $closing + 3;
            } else {
                $openingEnd = self::findOpeningTagEnd($html, $start);
                if ($openingEnd !== false) {
                    $end = self::findRawTextClosingEnd($html, $kind, $openingEnd + 1);
                }
            }

            $closed = $end !== false;
            $end = $closed ? $end : $htmlLength;
            $regions[] = [
                'start' => $start,
                'length' => $end - $start,
                'kind' => $kind,
                'closed' => $closed,
            ];
            $offset = $end;
        }

        return $regions;
    }

    private static function findTagOpening(string $html, string $tag, int $offset): int|false
    {
        $needle = '<' . $tag;
        while (($position = stripos($html, $needle, $offset)) !== false) {
            $boundary = $html[$position + strlen($needle)] ?? '';
            if ($boundary === '' || $boundary === '>' || $boundary === '/' || ctype_space($boundary)) {
                return $position;
            }
            $offset = $position + 1;
        }

        return false;
    }

    private static function findOpeningTagEnd(string $html, int $start): int|false
    {
        $quote = null;
        $length = strlen($html);
        for ($index = $start; $index < $length; $index++) {
            $character = $html[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                return $index;
            }
        }

        return false;
    }

    private static function findRawTextClosingEnd(string $html, string $tag, int $offset): int|false
    {
        $needle = '</' . $tag;
        while (($position = stripos($html, $needle, $offset)) !== false) {
            $index = $position + strlen($needle);
            $boundary = $html[$index] ?? '';
            if ($boundary === '>' || ctype_space($boundary)) {
                while (isset($html[$index]) && ctype_space($html[$index])) {
                    $index++;
                }
                if (($html[$index] ?? '') === '>') {
                    return $index + 1;
                }
            }
            $offset = $position + 1;
        }

        return false;
    }

    private static function findLastClosingTag(string $html, string $tag): ?int
    {
        $needle = '</' . $tag;
        $offset = 0;
        $last = null;
        while (($position = stripos($html, $needle, $offset)) !== false) {
            $index = $position + strlen($needle);
            while (isset($html[$index]) && ctype_space($html[$index])) {
                $index++;
            }
            if (($html[$index] ?? '') === '>') {
                $last = $position;
            }
            $offset = $position + 1;
        }

        return $last;
    }
}
