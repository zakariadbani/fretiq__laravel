<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'thumbnail_path',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * Campaigns that use this template.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
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
            'thumbnail_path' => 'nullable|string|max:255',
        ];
    }
}
