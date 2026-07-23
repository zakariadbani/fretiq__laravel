<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\Translation\LanguageResolver;

class CampaignTemplate extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'campaign_templates';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'subject',
        'html_content',
        'preview_text',
        'builder_state',
        'thumbnail_path',
        'zoho_template_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * builder_state is nullable JSON — {header_variant, hero_variant,
     * middle_variant, footer_variant, cta:{intent,label}, slots:{...},
     * preview_text}. middle_variant supports process, departures, kpi, and
     * benefits. NULL for classic (raw HTML) templates. Never re-parsed
     * out of html_content — see TemplateComposer docblock.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'builder_state' => 'array',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * Campaigns that use this template.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
    }

    /**
     * All AI/manual translations for this template.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CampaignTemplateTranslation::class);
    }

    // ── Translation helpers ────────────────────────────────────────────────────

    /**
     * Return the translation row for a given language, N+1-safe.
     *
     * If the translations relation is already loaded (e.g. eager-loaded in the
     * campaign run loop) we filter in PHP; otherwise we hit the DB once.
     */
    public function translationFor(string $lang): ?CampaignTemplateTranslation
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('language', $lang);
        }

        return $this->translations()->where('language', $lang)->first();
    }

    /**
     * MD5 hashes of the current source (FR) fields.
     *
     * Each field is null-coalesced to '' before hashing — ConvertEmptyStringsToNull
     * persists empty preview as NULL; both snapshot and staleness-check sides must
     * normalize identically so a null and an empty string are not considered a drift.
     *
     * @return array{subject: string, preview: string, body: string}
     */
    public function sourceHashes(): array
    {
        return [
            'subject' => md5((string) ($this->subject      ?? '')),
            'preview' => md5((string) ($this->preview_text ?? '')),
            'body'    => md5((string) ($this->html_content ?? '')),
        ];
    }

    /**
     * Return the subset of ['subject','preview','body'] whose source hash has
     * drifted from the stored snapshot on the given translation row.
     *
     * A null stored hash is treated as stale (translation predates hashing).
     *
     * @return array<string>
     */
    public function staleFieldsFor(CampaignTemplateTranslation $tr): array
    {
        $current = $this->sourceHashes();
        $stale   = [];

        if ($tr->src_subject_hash === null || $tr->src_subject_hash !== $current['subject']) {
            $stale[] = 'subject';
        }
        if ($tr->src_preview_hash === null || $tr->src_preview_hash !== $current['preview']) {
            $stale[] = 'preview';
        }
        if ($tr->src_body_hash === null || $tr->src_body_hash !== $current['body']) {
            $stale[] = 'body';
        }

        return $stale;
    }

    /**
     * Resolve the subject/html_content/preview_text/language to use for a
     * given contact's company country.
     *
     * Rules:
     *   - null/empty country, or francophone country → return FR base fields.
     *   - Any other non-empty country → try EN translation row; if none, fall
     *     back to FR base. (Staleness is advisory; the stored EN is used as-is.)
     *
     * @return array{subject: string, html_content: string, preview_text: string|null, language: string}
     */
    public function resolveFor(?string $country): array
    {
        $lang     = app(LanguageResolver::class)->forCountry($country);
        $baseLang = config('translation.base_language', 'fr');

        // Base language or null → use the template's own fields
        if ($lang === $baseLang) {
            return [
                'subject'      => (string) ($this->subject      ?? ''),
                'html_content' => (string) ($this->html_content ?? ''),
                'preview_text' => $this->preview_text,
                'language'     => $baseLang,
            ];
        }

        // Try the translation row for the resolved language
        $tr = $this->translationFor($lang);

        if ($tr !== null) {
            return [
                'subject'      => $tr->subject,
                'html_content' => $tr->html_content,
                'preview_text' => $tr->preview_text,
                'language'     => $lang,
            ];
        }

        // No translation row — fall back to the FR base
        return [
            'subject'      => (string) ($this->subject      ?? ''),
            'html_content' => (string) ($this->html_content ?? ''),
            'preview_text' => $this->preview_text,
            'language'     => $baseLang,
        ];
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'subject'      => 'required|string|max:255',
            'html_content' => 'required|string',
            'preview_text' => 'nullable|string|max:255',
            'builder_state' => 'nullable|array',
            'thumbnail_path' => 'nullable|string|max:255',
        ];
    }
}
