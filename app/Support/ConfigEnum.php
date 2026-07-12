<?php

namespace App\Support;

class ConfigEnum
{
    /**
     * Build a Laravel validation "in:" rule from a global.data.{$key} enum map,
     * e.g. ConfigEnum::in('company_relationships') => 'in:prospect,client,...'.
     *
     * Emits the exact same string the ~17 model rules() sites built inline via
     * 'in:' . implode(',', array_keys(config('global.data.X', []))).
     */
    public static function in(string $key): string
    {
        return 'in:' . implode(',', array_keys(config('global.data.' . $key, [])));
    }
}
