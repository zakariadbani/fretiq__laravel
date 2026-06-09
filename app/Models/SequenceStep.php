<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceStep extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sequence_steps';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'sequence_id',
        'step_no',
        'delay_days',
        'template_id',
        'subject',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The parent sequence this step belongs to.
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /**
     * The email template used for this step.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'template_id');
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
            'sequence_id' => 'required|integer|exists:sequences,id',
            'step_no'     => 'required|integer|min:1',
            'delay_days'  => 'nullable|integer|min:0',
            'template_id' => 'required|integer|exists:campaign_templates,id',
            'subject'     => 'nullable|string|max:255',
        ];
    }
}
