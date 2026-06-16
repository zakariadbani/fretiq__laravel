<?php

namespace App\Support;

use Illuminate\Support\Str;

class TrackingToken
{
    /**
     * Generate a 64-char lowercase hex tracking token.
     *
     * Derived from a SHA-256 of the given scalar parts joined with '|' plus a
     * random 32-char nonce (Str::random(32)), so it is effectively unique even
     * on retry. Pass only scalar id/step values — nulls, floats, or objects
     * would shift the SHA-256 preimage and must not be passed.
     */
    public static function generate(int|string ...$parts): string
    {
        return hash('sha256', implode('|', [...$parts, Str::random(32)]));
    }
}
