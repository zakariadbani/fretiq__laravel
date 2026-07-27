<?php

namespace App\Services\Discovery;

final readonly class DiscoveryCollectionResult
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  bool  $searchProviderDown  True when the search provider stopped
     *                                    responding this invocation and there is
     *                                    no usable work (candidates) to fall back
     *                                    on — the caller should terminalize the
     *                                    run instead of retrying it.
     */
    public function __construct(
        public array $candidates,
        public bool $terminal,
        public bool $searchProviderDown = false,
    ) {}
}
