<?php

namespace App\Services\Zoho\V2\Marketing;

final readonly class MarketingScope
{
    /** @param list<string> $ownerIds */
    public function __construct(
        public ?int $commercialUserId,
        public array $ownerIds,
        public bool $crmVisible,
        public bool $mappingRequired,
        public bool $selectionInvalid = false,
    ) {}

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return [
            'commercial_user_id' => $this->commercialUserId,
            'crm_visible' => $this->crmVisible,
            'mapping_required' => $this->mappingRequired,
            'selection_invalid' => $this->selectionInvalid,
            'mapped_owner_count' => count($this->ownerIds),
        ];
    }

    /** @return array<string,mixed> */
    public function cacheFragment(): array
    {
        return [$this->commercialUserId, $this->ownerIds, $this->crmVisible, $this->mappingRequired, $this->selectionInvalid];
    }
}
