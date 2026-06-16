<?php

namespace Database\Factories;

use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for CampaignTemplateTranslation.
 *
 * Defaults to an EN translation with fresh (non-stale) sub-hashes.
 * Use ->stale() state to create a stale translation.
 */
class CampaignTemplateTranslationFactory extends Factory
{
    protected $model = CampaignTemplateTranslation::class;

    public function definition(): array
    {
        return [
            'campaign_template_id' => CampaignTemplate::factory(),
            'language'             => 'en',
            'subject'              => $this->faker->sentence(6),
            'html_content'         => '<p>' . $this->faker->paragraph() . '</p>',
            'preview_text'         => $this->faker->sentence(10),
            'is_ai_generated'      => true,
            'reviewed_at'          => null,
            // Hashes default to md5('') — callers needing correct hashes should
            // call $template->sourceHashes() and pass them explicitly.
            'src_subject_hash'     => md5(''),
            'src_preview_hash'     => md5(''),
            'src_body_hash'        => md5(''),
        ];
    }

    /**
     * Mark the translation as manually edited (not AI generated).
     */
    public function manual(): static
    {
        return $this->state(['is_ai_generated' => false]);
    }

    /**
     * Mark the translation as reviewed.
     */
    public function reviewed(): static
    {
        return $this->state(['reviewed_at' => now()]);
    }

    /**
     * Mark the translation as stale (src hashes clearly different from current).
     */
    public function stale(): static
    {
        return $this->state([
            'src_subject_hash' => md5('stale_placeholder_' . uniqid()),
            'src_preview_hash' => md5('stale_placeholder_' . uniqid()),
            'src_body_hash'    => md5('stale_placeholder_' . uniqid()),
        ]);
    }
}
