<?php

namespace App\Services\Translation;

/**
 * HtmlTextSegmenter — pure-PHP HTML tokeniser for translation workflows.
 *
 * Splits an HTML string into an ordered list of tokens, each carrying a
 * 'type' ('markup' or 'text') and a 'value' (the raw string slice).
 *
 * Invariant: reassemble(segment($html)) === $html  (byte-for-byte).
 *
 * Strategy: walk the string with preg_match_all using a single combined
 * alternation that matches all "opaque" markup blocks (style, script, head),
 * HTML comments, and individual tags. The gaps between matches are text nodes.
 *
 * No DOMDocument — DOMDocument re-serialises and can mutate the HTML.
 */
class HtmlTextSegmenter
{
    /**
     * Regex pattern that matches any "markup" fragment we want to preserve
     * verbatim and NOT send to the LLM.
     *
     * Precedence order (earlier alternatives shadow later ones):
     *   1. <style>…</style>  — CSS blocks (closed); <style>…EOF if unclosed
     *   2. <script>…</script>— JS blocks (closed); <script>…EOF if unclosed
     *   3. <head>…</head>    — full head section (closed); <head>…EOF if unclosed
     *   4. <!--…-->          — HTML comments (preserves Outlook <!--[if mso]> blocks)
     *   5. <…>               — individual tags (open, close, self-closing, doctype)
     *
     * The unclosed fallback alternatives (e.g. `<style\b[^>]*>.*`) consume from
     * the opening tag to end-of-string via `.*` in DOTALL mode.  The closed
     * non-greedy alternative is listed first so it wins whenever a proper closer
     * exists; the fallback fires only when the string has no closing tag.
     */
    private const MARKUP_PATTERN = <<<'REGEX'
/
    <style\b[^>]*>.*?<\/style>     # CSS blocks (closed)
  | <style\b[^>]*>.*               # CSS opener with no closer → consume to EOF
  | <script\b[^>]*>.*?<\/script>   # JS blocks (closed)
  | <script\b[^>]*>.*              # JS opener with no closer → consume to EOF
  | <head\b[^>]*>.*?<\/head>       # head section (closed)
  | <head\b[^>]*>.*                # head opener with no closer → consume to EOF
  | <!--.*?-->                     # HTML comments (including Outlook conditionals)
  | <[^>]+>                        # individual tags
/xsi
REGEX;

    /**
     * Tokenise $html into an ordered stream of markup/text tokens.
     *
     * Guarantees: array_column(segment($html), 'value') joined === $html.
     *
     * @return array<int, array{type: 'markup'|'text', value: string}>
     */
    public function segment(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $tokens  = [];
        $offset  = 0;
        $len     = strlen($html);

        // Find all markup matches with their byte offsets
        preg_match_all(self::MARKUP_PATTERN, $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$matchValue, $matchOffset]) {
            // Text gap before this markup match
            if ($matchOffset > $offset) {
                $tokens[] = [
                    'type'  => 'text',
                    'value' => substr($html, $offset, $matchOffset - $offset),
                ];
            }

            // The markup token itself
            $tokens[] = [
                'type'  => 'markup',
                'value' => $matchValue,
            ];

            $offset = $matchOffset + strlen($matchValue);
        }

        // Trailing text after the last markup match
        if ($offset < $len) {
            $tokens[] = [
                'type'  => 'text',
                'value' => substr($html, $offset),
            ];
        }

        return $tokens;
    }

    /**
     * Return the translatable text runs from a token stream.
     *
     * A run is included only when:
     *   - The token type is 'text'
     *   - The trimmed value is non-empty (not whitespace-only)
     *   - The trimmed value is NOT a bare merge-tag (e.g. "{{contact.name}}")
     *
     * @param  array<int, array{type: string, value: string}> $tokens
     * @return array<int, array{index: int, value: string}>   index is position in $tokens
     */
    public function translatableRuns(array $tokens): array
    {
        $runs = [];

        foreach ($tokens as $i => $token) {
            if ($token['type'] !== 'text') {
                continue;
            }

            $trimmed = trim($token['value']);

            if ($trimmed === '') {
                continue;
            }

            // Skip bare merge-tags like "{{contact.name}}" or "{{ unsubscribe_url }}"
            if (preg_match('/^\s*\{\{[^}]+\}\}\s*$/u', $token['value'])) {
                continue;
            }

            $runs[] = [
                'index' => $i,
                'value' => $token['value'],
            ];
        }

        return $runs;
    }

    /**
     * Rebuild the original HTML from a (possibly mutated) token stream.
     *
     * For the invariant to hold, only 'value' entries should be changed;
     * never add, remove, or reorder tokens.
     *
     * @param  array<int, array{type: string, value: string}> $tokens
     * @return string
     */
    public function reassemble(array $tokens): string
    {
        return implode('', array_column($tokens, 'value'));
    }
}
