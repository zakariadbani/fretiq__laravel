<?php

namespace App\Models;

use App\Services\Settings\SettingService;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'group_name',
        'setting_key',
        'value',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'array',
    ];

    // ── Static proxy helpers ────────────────────────────────────────────────────

    /**
     * Get a setting value by dotted key, delegating to SettingService.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return app(SettingService::class)->get($key, $default);
    }

    /**
     * Set a setting value by dotted key, delegating to SettingService.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        app(SettingService::class)->set($key, $value);
    }

    /**
     * Check whether a setting key exists in the loaded map.
     *
     * @param  string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        $map = app(SettingService::class)->all();

        return array_key_exists($key, $map);
    }
}
