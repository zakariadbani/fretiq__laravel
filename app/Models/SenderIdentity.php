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
    ];

    protected $hidden = ['imap_password'];

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
        ];
    }
}
