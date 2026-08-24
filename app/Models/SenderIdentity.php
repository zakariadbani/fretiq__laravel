<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;

class SenderIdentity extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sender_identities';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'email',
        'reply_to',
        'signature_html',
        'is_default',
        'is_active',
        'imap_host',
        'imap_port',
        'imap_username',
        'imap_password',
        'imap_encryption',
        'imap_validate_cert',
        'imap_enabled',
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_hourly_limit',
        'smtp_daily_limit',
    ];

    protected $hidden = ['imap_password', 'smtp_password'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
        'imap_password' => 'encrypted',
        'imap_port' => 'integer',
        'imap_validate_cert' => 'boolean',
        'imap_enabled' => 'boolean',
        'last_polled_at' => 'datetime',
        'smtp_enabled' => 'boolean',
        'smtp_port' => 'integer',
        'smtp_password' => 'encrypted',
        'smtp_hourly_limit' => 'integer',
        'smtp_daily_limit' => 'integer',
    ];

    /**
     * Validation rules for store/update via Crudable.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:255',
            'email'          => 'required|email|max:191',
            'reply_to'       => 'nullable|email|max:191',
            'signature_html' => 'nullable|string',
            'is_default'     => 'boolean',
            'is_active'      => 'boolean',
            'imap_host'      => 'nullable|required_if:imap_enabled,1|string|max:255',
            'imap_port'      => 'nullable|integer|min:1|max:65535',
            'imap_username'  => 'nullable|required_if:imap_enabled,1|string|max:255',
            'imap_password'  => 'nullable|required_if:imap_enabled,1|string|max:1000',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'imap_validate_cert' => 'boolean',
            'imap_enabled'   => 'boolean',
            'smtp_enabled'   => 'boolean',
            'smtp_host'      => 'nullable|required_if:smtp_enabled,1|string|max:255',
            'smtp_port'      => 'nullable|integer|min:1|max:65535',
            'smtp_username'  => 'nullable|required_if:smtp_enabled,1|string|max:255',
            'smtp_password'  => 'nullable|required_if:smtp_enabled,1|string|max:1000',
            'smtp_encryption' => 'nullable|in:tls,ssl',
            'smtp_hourly_limit' => 'nullable|integer|min:1|max:100',
            'smtp_daily_limit' => 'nullable|integer|min:1|max:500',
        ];
    }

    /**
     * Email of the default+active sender identity, for compose-time
     * defaults (e.g. the builder footer contact email) — a real DB read,
     * so callers that must stay DB-free (TemplateComposer::compose()) call
     * this at the call site and pass the resolved value in, never inside.
     */
    public static function defaultContactEmail(): ?string
    {
        return static::query()->where('is_default', true)->where('is_active', true)->value('email');
    }

    public function hasCompleteSmtpConfiguration(): bool
    {
        return $this->is_active
            && $this->smtp_enabled
            && filled($this->smtp_host)
            && filled($this->smtp_port)
            && filled($this->smtp_username)
            && filled($this->smtp_password)
            && (int) $this->smtp_hourly_limit > 0
            && (int) $this->smtp_daily_limit > 0;
    }

    /**
     * A risky accept-all address is sendable only when inbox feedback is both
     * configured and recently proven by a successful poll.
     */
    public function hasHealthyBounceFeedback(): bool
    {
        $freshAfter = now()->subMinutes(
            max(1, (int) config('prospecting.bounce.feedback_health_minutes', 15)),
        );

        return $this->is_active
            && $this->imap_enabled
            && filled($this->imap_host)
            && (int) $this->imap_port > 0
            && filled($this->imap_username)
            && filled($this->imap_password)
            && filled($this->imap_encryption)
            && (bool) Setting::get('automatisation.cron_enabled', true)
            && (bool) Setting::get('automatisation.inbox_poll', true)
            && (int) $this->consecutive_poll_failures === 0
            && blank($this->last_poll_error)
            && $this->last_polled_at !== null
            && $this->last_polled_at->gte($freshAfter);
    }
}
