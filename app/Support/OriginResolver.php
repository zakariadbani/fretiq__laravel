<?php

namespace App\Support;

/**
 * Derives the "Origine" display for a company/contact from its source +
 * provenance links (criteria / prospect batch), without a schema change.
 * `discovered` covers both "Critère" (auto-discovery) and "Lot" (a staged
 * prospecting batch) — this resolver tells them apart at read time.
 *
 * ponytail: origin computed on read; denormalize into a column if listings
 * get slow (see structure/specs backend-ui-ux-contract.md provenance note).
 *
 * @phpstan-type Origin array{label: string, route: ?string, id: ?int}
 */
final class OriginResolver
{
    /**
     * @return array{label: string, route: ?string, id: ?int}
     */
    public static function forCompany(?string $source, ?int $criteriaId, ?int $batchId): array
    {
        return match (true) {
            $source === 'hunter' => ['label' => 'Import', 'route' => null, 'id' => null],
            $source === 'zoho' => ['label' => 'Zoho', 'route' => null, 'id' => null],
            $source === 'discovered' && $criteriaId !== null => ['label' => 'Critère', 'route' => 'admin.prospect_criteria.view', 'id' => $criteriaId],
            $source === 'discovered' && $batchId !== null => ['label' => 'Lot', 'route' => 'admin.prospect_batches.view', 'id' => $batchId],
            default => ['label' => 'Manuel', 'route' => null, 'id' => null],
        };
    }

    /**
     * The contact's own source wins first (e.g. a `hunter`-imported contact
     * attached to a pre-existing `discovered` company must still show
     * "Import", not the company's "Critère"/"Lot"). Otherwise its own batch
     * pivot wins; otherwise fall back to the company's origin.
     *
     * @return array{label: string, route: ?string, id: ?int}
     */
    public static function forContact(?string $contactSource, ?int $ownBatchId, ?string $companySource, ?int $companyCriteriaId, ?int $companyBatchId): array
    {
        if ($contactSource === 'hunter') {
            return ['label' => 'Import', 'route' => null, 'id' => null];
        }

        if ($contactSource === 'zoho') {
            return ['label' => 'Zoho', 'route' => null, 'id' => null];
        }

        if ($contactSource === 'manual') {
            return ['label' => 'Manuel', 'route' => null, 'id' => null];
        }

        if ($ownBatchId !== null) {
            return ['label' => 'Lot', 'route' => 'admin.prospect_batches.view', 'id' => $ownBatchId];
        }

        return self::forCompany($companySource, $companyCriteriaId, $companyBatchId);
    }
}
