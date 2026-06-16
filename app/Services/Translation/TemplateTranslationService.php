<?php

namespace App\Services\Translation;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;

/**
 * TemplateTranslationService — orchestrates AI and manual translations for
 * campaign templates.
 *
 * All three public methods are intentionally narrow:
 *   - translate()    — AI batch, one row per target language
 *   - saveManual()   — user-edited EN override
 *   - setReviewed()  — toggle the advisory reviewed_at timestamp
 *
 * Invariants:
 *   - base_language is never a translation target.
 *   - A null result from the driver leaves the existing row untouched (fail-safe).
 *   - src_*_hash is always snapshotted from the CURRENT base at the time of
 *     save so that staleness detection works correctly going forward.
 */
class TemplateTranslationService
{
    public function __construct(
        private readonly GeminiTranslationDriver $driver,
    ) {}

    /**
     * Translate a template into one or more target languages via Gemini.
     *
     * @param  CampaignTemplate $t
     * @param  string[]|null    $targets  Defaults to config('translation.target_languages').
     *                                    base_language is silently excluded.
     *
     * @return array{translated: string[], failed: string[]}
     */
    public function translate(CampaignTemplate $t, ?array $targets = null): array
    {
        $baseLang = config('translation.base_language', 'fr');
        $targets  = $targets ?? config('translation.target_languages', ['en']);

        // Reject the base language as a translation target
        $targets = array_values(array_filter($targets, fn ($l) => $l !== $baseLang));

        $translated = [];
        $failed     = [];

        foreach ($targets as $lang) {
            $result = $this->driver->translate(
                subject:    $t->subject,
                preview:    $t->preview_text,
                html:       $t->html_content,
                sourceLang: $baseLang,
                targetLang: $lang,
            );

            if ($result === null) {
                // Driver failure — leave any existing row untouched
                $failed[] = $lang;
                continue;
            }

            $hashes = $t->sourceHashes();

            CampaignTemplateTranslation::updateOrCreate(
                [
                    'campaign_template_id' => $t->id,
                    'language'             => $lang,
                ],
                [
                    'subject'          => $result['subject'],
                    'html_content'     => $result['html_content'],
                    'preview_text'     => $result['preview_text'],
                    'is_ai_generated'  => true,
                    'reviewed_at'      => null,
                    'src_subject_hash' => $hashes['subject'],
                    'src_preview_hash' => $hashes['preview'],
                    'src_body_hash'    => $hashes['body'],
                ]
            );

            $translated[] = $lang;
        }

        return [
            'translated' => $translated,
            'failed'     => $failed,
        ];
    }

    /**
     * Persist a user-edited translation (manual override).
     *
     * Sets is_ai_generated = false, reviewed_at = null, and snapshots the
     * current base hashes so the row is "à jour" immediately after saving.
     *
     * @param  array{subject: string, html_content: string, preview_text?: string|null} $fields
     */
    public function saveManual(
        CampaignTemplate $t,
        string           $lang,
        array            $fields,
    ): CampaignTemplateTranslation {
        $hashes = $t->sourceHashes();

        /** @var CampaignTemplateTranslation */
        return CampaignTemplateTranslation::updateOrCreate(
            [
                'campaign_template_id' => $t->id,
                'language'             => $lang,
            ],
            [
                'subject'          => $fields['subject'],
                'html_content'     => $fields['html_content'],
                'preview_text'     => $fields['preview_text'] ?? null,
                'is_ai_generated'  => false,
                'reviewed_at'      => null,
                'src_subject_hash' => $hashes['subject'],
                'src_preview_hash' => $hashes['preview'],
                'src_body_hash'    => $hashes['body'],
            ]
        );
    }

    /**
     * Set or clear the advisory reviewed_at timestamp on a translation row.
     *
     * Returns the updated row, or null if no row exists for (template, lang).
     */
    public function setReviewed(
        CampaignTemplate $t,
        string           $lang,
        bool             $reviewed,
    ): ?CampaignTemplateTranslation {
        $tr = $t->translations()->where('language', $lang)->first();

        if ($tr === null) {
            return null;
        }

        $tr->reviewed_at = $reviewed ? now() : null;
        $tr->save();

        return $tr;
    }
}
