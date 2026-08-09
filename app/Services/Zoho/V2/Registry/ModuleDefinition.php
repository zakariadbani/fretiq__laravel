<?php

namespace App\Services\Zoho\V2\Registry;

final readonly class ModuleDefinition
{
    /**
     * @param  list<string>  $dependencies
     * @param  list<string>  $visibilityAllowlist
     * @param  array<string, scalar>  $listQuery  Live-verified query parameters required for record enumeration.
     * @param  array<string, scalar>  $recordQuery  Live-verified query parameters required for specific-record hydration.
     */
    public function __construct(
        public string $key,
        public string $apiName,
        public string $modelClass,
        public string $table,
        public array $dependencies = [],
        public string $fetchStrategy = 'records',
        public bool $supportsModifiedTime = false,
        public ?string $activityType = null,
        public ?string $submodule = null,
        public array $visibilityAllowlist = [],
        public bool $activationGated = false,
        public ?string $activationNote = null,
        public bool $bulkReadSupported = false,
        public array $listQuery = [],
        public array $recordQuery = [],
        public ?string $bulkReadNote = null,
    ) {}

    public function queryFingerprint(string $mode): string
    {
        $query = $this->listQuery;
        ksort($query);

        return hash('sha256', json_encode([
            'api_name' => $this->apiName,
            'submodule' => $this->submodule ?? '',
            'mode' => $mode,
            'fetch_strategy' => $this->fetchStrategy,
            'query' => $query,
        ], JSON_THROW_ON_ERROR));
    }
}
