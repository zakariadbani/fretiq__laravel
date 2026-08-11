<?php

namespace App\Services\Providers;

use App\Models\ProviderCall;

final readonly class ProviderExecution
{
    public function __construct(
        public ProviderCall $call,
        public ?ProviderResponse $response,
        public bool $replayed,
    ) {}
}
