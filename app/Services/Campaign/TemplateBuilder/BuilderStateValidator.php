<?php

namespace App\Services\Campaign\TemplateBuilder;

use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * BuilderStateValidator — the single validation authority for builder_state.
 *
 * Two entry points:
 *   - validate(array $state): array         → full state (from the form / stored
 *     builder_state). Throws Illuminate\Validation\ValidationException with
 *     field-level keys (e.g. "slots.hero_title", "hero_variant") on failure.
 *     Returns a normalized (trimmed, re-indexed) copy of $state on success.
 *   - validateSuggestion(array $suggestion): ?array → AI output shape
 *     (subject/preview_text/middle_variant/slots/cta_intent/cta_label). Never
 *     throws — returns null on ANY drift (same guard posture as
 *     GeminiTranslationDriver), so the AI can never inject invalid state.
 *
 * Bounds live on SectionCatalog (single source of truth); this class only
 * wires them into Laravel validation rules plus the two guards Laravel's
 * declarative rules can't express: merge-tag whitelist and header→hero
 * correspondence.
 */
class BuilderStateValidator
{
    /**
     * Hard cap on the JSON-encoded size of an incoming builder_state /
     * suggestion payload — a defense against a request carrying an
     * attacker-sized number of sibling keys (each individually bounded, but
     * unbounded in COUNT before this check existed). A legitimate payload
     * (every documented field at its max length) encodes to well under half
     * of this — see plan item 5 ("recursive merge-tag scan over
     * attacker-sized payloads").
     */
    public const MAX_ENCODED_BYTES = 20480; // 20 KB

    /**
     * Whitelisted key sets — every object level in builder_state /
     * suggestion is validated against exactly one of these. Anything else
     * present is rejected outright by applyUnknownKeyGuards() rather than
     * silently ignored: normalizeSlots() already discards unrecognized keys,
     * which used to mean an attacker could pad the payload with any number
     * of never-validated keys that the merge-tag scan still recursed into.
     */
    private const STATE_KEYS = ['header_variant', 'hero_variant', 'middle_variant', 'footer_variant', 'cta', 'preview_text', 'slots'];

    private const SUGGESTION_KEYS = ['subject', 'preview_text', 'middle_variant', 'cta_intent', 'cta_label', 'slots'];

    private const CTA_KEYS = ['intent', 'label'];

    private const SLOTS_KEYS = [
        'hero_title', 'intro', 'bullets', 'closing_line',
        'departures', 'kpis', 'benefits', 'process_steps', 'process_highlight',
    ];

    private const DEPARTURE_ITEM_KEYS = ['origin', 'frequency'];

    private const KPI_ITEM_KEYS = ['value', 'label'];

    private const BENEFIT_ITEM_KEYS = ['title', 'text'];

    /**
     * Rejects a leading "<" immediately followed by a letter, "/", or "!" —
     * i.e. the opening of a tag, closing tag, or comment/doctype marker.
     * Deliberately narrow: legitimate French B2B copy sometimes uses "<" as a
     * comparison symbol ("moins de <5% de casse", "délai <48h") — those never
     * match (a digit follows, not a letter). See applyMarkupRejectionRules().
     */
    private const MARKUP_REJECTION_RULE = 'not_regex:/<[a-z\/!]/i';

    /**
     * Every AI-suggested string slot in validateSuggestion()'s rule set —
     * NOT applied to validate() (see applyMarkupRejectionRules() docblock).
     */
    private const SUGGESTION_MARKUP_FIELDS = [
        'subject',
        'preview_text',
        'cta_label',
        'slots.hero_title',
        'slots.intro.*',
        'slots.bullets.*',
        'slots.closing_line',
        'slots.departures.*.origin',
        'slots.departures.*.frequency',
        'slots.kpis.*.value',
        'slots.kpis.*.label',
        'slots.benefits.*.title',
        'slots.benefits.*.text',
        'slots.process_steps.*',
        'slots.process_highlight',
    ];

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(array $state): array
    {
        $this->assertWithinSizeLimit($state, 'builder_state');

        $header = $state['header_variant'] ?? null;
        $middle = $state['middle_variant'] ?? null;

        $rules = $this->baseRules(includeCta: true, includeVariantKeys: true);
        $this->addMiddleVariantRules($rules, is_string($middle) ? $middle : null);

        $validator = ValidatorFacade::make($state, $rules);
        $validator->after(function (Validator $v) use ($state, $header) {
            $this->applyUnknownKeyGuards($v, $state, isSuggestion: false);
            $this->applyMergeTagGuard($v, $this->mergeTagScanTarget($state, isSuggestion: false));
            $this->applyHeroCorrespondenceGuard($v, $state, is_string($header) ? $header : null);
        });

        // Throws ValidationException (field-level keys) on failure.
        $validator->validate();

        return $this->normalizeState($state, (string) $middle);
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>|null
     */
    public function validateSuggestion(array $suggestion): ?array
    {
        try {
            $this->assertWithinSizeLimit($suggestion, 'suggestion');

            $middle = $suggestion['middle_variant'] ?? null;

            $rules = $this->baseRules(includeCta: false, includeVariantKeys: false);
            $rules['subject']      = ['required', 'string', 'max:255'];
            $rules['preview_text'] = ['nullable', 'string', 'max:' . SectionCatalog::PREVIEW_TEXT_MAX];
            $rules['cta_intent']   = ['required', 'string', Rule::in(array_keys(SectionCatalog::ctaIntents()))];
            $rules['cta_label']    = ['required', 'string', 'max:' . SectionCatalog::CTA_LABEL_MAX];
            $rules['middle_variant'] = ['required', 'string', Rule::in(SectionCatalog::MIDDLES)];

            $this->addMiddleVariantRules($rules, is_string($middle) ? $middle : null);
            $this->applyMarkupRejectionRules($rules);

            $validator = ValidatorFacade::make($suggestion, $rules);
            $validator->after(function (Validator $v) use ($suggestion) {
                $this->applyUnknownKeyGuards($v, $suggestion, isSuggestion: true);
                $this->applyMergeTagGuard($v, $this->mergeTagScanTarget($suggestion, isSuggestion: true));
            });

            if ($validator->fails()) {
                return null;
            }

            return $this->normalizeSuggestion($suggestion, (string) $middle);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Rule building ──────────────────────────────────────────────────────────

    /**
     * The rules shared between validate() and validateSuggestion() for the
     * `slots` object — hero_title/intro/bullets are common to every variant
     * combo, regardless of whether the caller is the full builder_state
     * (includeCta + includeVariantKeys true) or an AI suggestion (both false —
     * validateSuggestion() adds its own flat cta_intent/cta_label/middle_variant
     * rules, since the AI never picks header/footer/hero).
     *
     * @return array<string, array<int, mixed>>
     */
    private function baseRules(bool $includeCta, bool $includeVariantKeys): array
    {
        $rules = [
            'slots'             => ['required', 'array'],
            'slots.hero_title'  => ['required', 'string', 'max:' . SectionCatalog::HERO_TITLE_MAX],
            'slots.intro'       => ['required', 'array', 'min:' . SectionCatalog::INTRO_MIN, 'max:' . SectionCatalog::INTRO_MAX],
            'slots.intro.*'     => ['required', 'string', 'max:' . SectionCatalog::INTRO_ITEM_MAX],
            'slots.bullets'     => ['required', 'array', 'min:' . SectionCatalog::BULLETS_MIN, 'max:' . SectionCatalog::BULLETS_MAX],
            'slots.bullets.*'   => ['required', 'string', 'max:' . SectionCatalog::BULLET_ITEM_MAX],
            // Optional — falls back to SectionCatalog::DEFAULT_CLOSING_LINE when
            // absent/blank (closing-signature.blade.php). The signature block
            // itself ("Cordialement, L'équipe TCL Transport") stays fixed.
            'slots.closing_line' => ['nullable', 'string', 'max:' . SectionCatalog::CLOSING_LINE_MAX],
            'preview_text'      => ['nullable', 'string', 'max:' . SectionCatalog::PREVIEW_TEXT_MAX],
        ];

        if ($includeCta) {
            $rules['cta']        = ['required', 'array'];
            $rules['cta.intent'] = ['required', 'string', Rule::in(array_keys(SectionCatalog::ctaIntents()))];
            $rules['cta.label']  = ['required', 'string', 'max:' . SectionCatalog::CTA_LABEL_MAX];
        }

        if ($includeVariantKeys) {
            $rules['header_variant'] = ['required', 'string', Rule::in(SectionCatalog::HEADERS)];
            $rules['footer_variant'] = ['required', 'string', Rule::in(SectionCatalog::FOOTERS)];
            $rules['middle_variant'] = ['required', 'string', Rule::in(SectionCatalog::MIDDLES)];
            $rules['hero_variant']   = ['required', 'string', Rule::in(SectionCatalog::HEROES)];
        }

        return $rules;
    }

    /**
     * Add the slot rules specific to the active middle_variant. Unknown/absent
     * middle_variant adds nothing extra — the base "middle_variant in [...]"
     * (or "required") rule already fails validation in that case.
     *
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function addMiddleVariantRules(array &$rules, ?string $middle): void
    {
        if ($middle === 'departures') {
            $rules['slots.departures']            = ['required', 'array', 'min:' . SectionCatalog::DEPARTURES_MIN, 'max:' . SectionCatalog::DEPARTURES_MAX];
            $rules['slots.departures.*.origin']    = ['required', 'string', 'max:' . SectionCatalog::DEPARTURE_FIELD_MAX];
            $rules['slots.departures.*.frequency'] = ['required', 'string', 'max:' . SectionCatalog::DEPARTURE_FIELD_MAX];
        } elseif ($middle === 'kpi') {
            $rules['slots.kpis']         = ['required', 'array', 'size:' . SectionCatalog::KPI_COUNT];
            $rules['slots.kpis.*.value'] = ['required', 'string', 'max:' . SectionCatalog::KPI_VALUE_MAX];
            $rules['slots.kpis.*.label'] = ['required', 'string', 'max:' . SectionCatalog::KPI_LABEL_MAX];
        } elseif ($middle === 'benefits') {
            $rules['slots.benefits']         = ['required', 'array', 'size:' . SectionCatalog::BENEFIT_COUNT];
            $rules['slots.benefits.*.title'] = ['required', 'string', 'max:' . SectionCatalog::BENEFIT_TITLE_MAX];
            $rules['slots.benefits.*.text']  = ['required', 'string', 'max:' . SectionCatalog::BENEFIT_TEXT_MAX];
        } elseif ($middle === 'process') {
            $rules['slots.process_steps']     = ['required', 'array', 'size:' . SectionCatalog::PROCESS_STEP_COUNT];
            $rules['slots.process_steps.*']   = ['required', 'string', 'max:' . SectionCatalog::PROCESS_STEP_MAX];
            $rules['slots.process_highlight'] = ['required', 'string', 'max:' . SectionCatalog::PROCESS_HIGHLIGHT_MAX];
        }
    }

    /**
     * Reject literal markup in every AI-supplied string slot — applied ONLY
     * to validateSuggestion(), never to validate(). "The AI never emits
     * HTML" is otherwise enforced purely by prompt instruction (rule 3 in
     * GeminiTemplateSuggestionService::buildPrompt()); a compromised or
     * drifted model response could still validate as a bounded plain string
     * and render as visible literal markup to the recipient (escaped by
     * Blade, so not an XSS risk, but visibly wrong output).
     *
     * Deliberately NOT applied to validate() — a human authoring in the
     * builder form who genuinely types a "<" (e.g. copy-pasting a snippet
     * with angle brackets) should see a clear field-level validation error
     * like every other bound, not have their AI-only guard silently reject
     * or coerce something never designed for manual entry.
     *
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function applyMarkupRejectionRules(array &$rules): void
    {
        foreach (self::SUGGESTION_MARKUP_FIELDS as $field) {
            if (! isset($rules[$field])) {
                continue; // not applicable to the active middle_variant
            }

            $rules[$field][] = self::MARKUP_REJECTION_RULE;
        }
    }

    // ── Guards Laravel's declarative rules can't express ───────────────────────

    /**
     * Header→hero correspondence is enforced exactly (not derived) so a
     * mismatched pair is rejected rather than silently coerced.
     *
     * @param  array<string, mixed>  $state
     */
    private function applyHeroCorrespondenceGuard(Validator $validator, array $state, ?string $header): void
    {
        if (! in_array($header, SectionCatalog::HEADERS, true)) {
            return; // already flagged by the header_variant rule
        }

        $expectedHero = SectionCatalog::heroForHeader($header);
        $actualHero   = $state['hero_variant'] ?? null;

        // Never interpolate $actualHero (attacker-controlled) into this message —
        // it reaches the client in `errors` and public/assets/js/custom/backend/
        // crud-form-handler.js renders it via Swal.fire({ html: ... }), which
        // would execute any markup smuggled through builder_state.hero_variant
        // (self-XSS). $header is safe: it is Rule::in-constrained above.
        if ($actualHero !== $expectedHero) {
            $validator->errors()->add(
                'hero_variant',
                "Le hero sélectionné ne correspond pas à l'en-tête [{$header}]."
            );
        }
    }

    /**
     * Reject unknown keys at every object level (top-level state/suggestion,
     * cta, slots, and each departures/kpis/benefits row) rather than letting
     * normalizeSlots() silently drop them later. Unknown keys used to be
     * invisible to every other guard yet still walked by the merge-tag scan —
     * an attacker could pad the payload with any number of never-validated
     * sibling keys.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyUnknownKeyGuards(Validator $validator, array $data, bool $isSuggestion): void
    {
        $this->rejectUnknownKeys($validator, $data, $isSuggestion ? self::SUGGESTION_KEYS : self::STATE_KEYS, 'builder_state');

        if (! $isSuggestion && isset($data['cta']) && is_array($data['cta'])) {
            $this->rejectUnknownKeys($validator, $data['cta'], self::CTA_KEYS, 'cta');
        }

        if (! isset($data['slots']) || ! is_array($data['slots'])) {
            return;
        }

        $this->rejectUnknownKeys($validator, $data['slots'], self::SLOTS_KEYS, 'slots');

        foreach ([
            'departures' => self::DEPARTURE_ITEM_KEYS,
            'kpis'       => self::KPI_ITEM_KEYS,
            'benefits'   => self::BENEFIT_ITEM_KEYS,
        ] as $slotKey => $itemKeys) {
            $this->rejectItemUnknownKeys($validator, $data['slots'][$slotKey] ?? null, $itemKeys, "slots.{$slotKey}");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $allowed
     */
    private function rejectUnknownKeys(Validator $validator, array $data, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $validator->errors()->add($path, "Clé non autorisée dans [{$path}] : [{$key}].");
        }
    }

    /**
     * @param  mixed  $rows
     * @param  array<int, string>  $allowed
     */
    private function rejectItemUnknownKeys(Validator $validator, mixed $rows, array $allowed, string $path): void
    {
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (is_array($row)) {
                $this->rejectUnknownKeys($validator, $row, $allowed, "{$path}.{$index}");
            }
        }
    }

    /**
     * Build the subset of $data the merge-tag scan is allowed to walk — only
     * whitelisted key paths, never raw/unknown branches. Before this, the
     * scan recursed over the ENTIRE raw payload (collectMergeTagErrors() is
     * itself unbounded-recursive), so an attacker-supplied branch with a huge
     * number of sibling keys — never touched by any other rule, since
     * normalizeSlots() discards anything outside the documented shape — still
     * cost CPU/memory on every request. Restricting the walk to the
     * documented string-bearing fields removes that cost independent of
     * applyUnknownKeyGuards() rejecting the request outright.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeTagScanTarget(array $data, bool $isSuggestion): array
    {
        $target = [];

        if ($isSuggestion) {
            if (isset($data['subject'])) {
                $target['subject'] = $data['subject'];
            }
            if (isset($data['cta_label'])) {
                $target['cta_label'] = $data['cta_label'];
            }
        } elseif (isset($data['cta']) && is_array($data['cta'])) {
            $target['cta'] = array_intersect_key($data['cta'], array_flip(self::CTA_KEYS));
        }

        if (isset($data['preview_text'])) {
            $target['preview_text'] = $data['preview_text'];
        }

        if (isset($data['slots']) && is_array($data['slots'])) {
            $slots = array_intersect_key($data['slots'], array_flip(self::SLOTS_KEYS));

            foreach ([
                'departures' => self::DEPARTURE_ITEM_KEYS,
                'kpis'       => self::KPI_ITEM_KEYS,
                'benefits'   => self::BENEFIT_ITEM_KEYS,
            ] as $slotKey => $itemKeys) {
                if (isset($slots[$slotKey]) && is_array($slots[$slotKey])) {
                    $slots[$slotKey] = array_map(
                        fn ($row) => is_array($row) ? array_intersect_key($row, array_flip($itemKeys)) : $row,
                        $slots[$slotKey]
                    );
                }
            }

            $target['slots'] = $slots;
        }

        return $target;
    }

    /**
     * Reject a payload whose JSON-encoded size exceeds MAX_ENCODED_BYTES —
     * see that constant's docblock. Thrown as a ValidationException so both
     * callers get their normal error-handling path: validate() lets it
     * propagate, validateSuggestion() catches it via its own try/Throwable
     * and returns null exactly like any other suggestion failure.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function assertWithinSizeLimit(array $data, string $field): void
    {
        $encoded = json_encode($data);

        if ($encoded !== false && strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw ValidationException::withMessages([
                $field => ['État du générateur trop volumineux (max ' . self::MAX_ENCODED_BYTES . ' octets encodés).'],
            ]);
        }
    }

    /**
     * Recursively scan every string leaf of $data for {{...}} merge tags and
     * flag any tag outside SectionCatalog::ALLOWED_MERGE_TAGS —
     * {{unsubscribe_url}} included, per plan. $data is expected to already be
     * whitelist-filtered (see mergeTagScanTarget()) — never the raw payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyMergeTagGuard(Validator $validator, array $data): void
    {
        foreach ($this->collectMergeTagErrors($data) as $path => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add($path, $message);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, string>>
     */
    private function collectMergeTagErrors(array $data, string $prefix = ''): array
    {
        $errors = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $errors = array_merge($errors, $this->collectMergeTagErrors($value, $path));
            } elseif (is_string($value)) {
                foreach ($this->invalidMergeTags($value) as $tag) {
                    $errors[$path][] = "Tag de fusion non autorisé : {{{$tag}}}";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function invalidMergeTags(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $text, $matches);

        $found = array_unique($matches[1]);

        return array_values(array_diff($found, SectionCatalog::ALLOWED_MERGE_TAGS));
    }

    // ── Normalization ──────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeState(array $state, string $middle): array
    {
        return [
            'header_variant' => $state['header_variant'],
            'hero_variant'   => $state['hero_variant'],
            'middle_variant' => $middle,
            'footer_variant' => $state['footer_variant'],
            'cta' => [
                'intent' => $state['cta']['intent'],
                'label'  => trim((string) $state['cta']['label']),
            ],
            'preview_text' => isset($state['preview_text']) ? trim((string) $state['preview_text']) : '',
            'slots'        => $this->normalizeSlots($state['slots'], $middle),
        ];
    }

    /**
     * @param  array<string, mixed>  $suggestion
     * @return array<string, mixed>
     */
    private function normalizeSuggestion(array $suggestion, string $middle): array
    {
        return [
            'subject'        => trim((string) $suggestion['subject']),
            'preview_text'   => isset($suggestion['preview_text']) ? trim((string) $suggestion['preview_text']) : '',
            'middle_variant' => $middle,
            'cta_intent'     => $suggestion['cta_intent'],
            'cta_label'      => trim((string) $suggestion['cta_label']),
            'slots'          => $this->normalizeSlots($suggestion['slots'], $middle),
        ];
    }

    /**
     * @param  array<string, mixed>  $slots
     * @return array<string, mixed>
     */
    private function normalizeSlots(array $slots, string $middle): array
    {
        $normalized = [
            'hero_title' => trim((string) $slots['hero_title']),
            'intro'      => array_values(array_map(fn ($v) => trim((string) $v), $slots['intro'])),
            'bullets'    => array_values(array_map(fn ($v) => trim((string) $v), $slots['bullets'])),
        ];

        // closing_line is OPTIONAL — omit the key entirely when blank/absent so
        // closing-signature.blade.php's `$slots['closing_line'] ?? DEFAULT_CLOSING_LINE`
        // correctly falls back (an empty-string value would defeat `??`, which
        // only triggers on null/unset).
        $closingLine = isset($slots['closing_line']) ? trim((string) $slots['closing_line']) : '';
        if ($closingLine !== '') {
            $normalized['closing_line'] = $closingLine;
        }

        if ($middle === 'departures') {
            $normalized['departures'] = array_values(array_map(fn ($row) => [
                'origin'    => trim((string) $row['origin']),
                'frequency' => trim((string) $row['frequency']),
            ], $slots['departures']));
        } elseif ($middle === 'kpi') {
            $normalized['kpis'] = array_values(array_map(fn ($row) => [
                'value' => trim((string) $row['value']),
                'label' => trim((string) $row['label']),
            ], $slots['kpis']));
        } elseif ($middle === 'benefits') {
            $normalized['benefits'] = array_values(array_map(fn ($row) => [
                'title' => trim((string) $row['title']),
                'text'  => trim((string) $row['text']),
            ], $slots['benefits']));
        } elseif ($middle === 'process') {
            $normalized['process_steps'] = array_values(array_map(
                fn ($step) => trim((string) $step),
                $slots['process_steps']
            ));
            $normalized['process_highlight'] = trim((string) $slots['process_highlight']);
        }

        return $normalized;
    }
}
