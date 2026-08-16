<?php

namespace App\Services\Discovery;

use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Models\Setting;

/**
 * Resolves the shared automatic-enrichment gate before any provider admission.
 */
final class AutomaticEnrichmentDecision
{
    private function __construct(
        public readonly bool $autoEnrich,
        public readonly int $minScore,
        public readonly bool $excluded,
        public readonly bool $alreadyHunterEmpty,
        public readonly ?string $skipStatus,
    ) {}

    public static function for(
        ProspectCriteria $criteria,
        bool $autoScoring,
        ?int $score,
        bool $excludeFlag,
        ?string $currentEnrichmentStatus,
    ): self {
        return self::fromResolvedRules(
            self::resolveRules($criteria),
            $autoScoring,
            $score,
            $excludeFlag,
            $currentEnrichmentStatus,
        );
    }

    public static function resolveRules(ProspectCriteria $criteria): AutomaticEnrichmentRules
    {
        return new AutomaticEnrichmentRules(
            $criteria->auto_enrich ?? (bool) Setting::get('decouverte.auto_enrich', false),
            (int) ($criteria->min_score_enrich ?? Setting::get('decouverte.min_score_enrich', 50)),
        );
    }

    public static function fromResolvedRules(
        AutomaticEnrichmentRules $rules,
        bool $autoScoring,
        ?int $score,
        bool $excludeFlag,
        ?string $currentEnrichmentStatus,
    ): self {
        $excluded = $autoScoring && $excludeFlag;

        $skipStatus = match (true) {
            $excluded => Company::ENRICHMENT_SKIPPED_EXCLUDED,
            ! $rules->autoEnrich => Company::ENRICHMENT_SKIPPED_ENRICH_OFF,
            $autoScoring && $score < $rules->minScore => Company::ENRICHMENT_SKIPPED_LOW_SCORE,
            default => null,
        };

        return new self(
            $rules->autoEnrich,
            $rules->minScore,
            $excluded,
            $currentEnrichmentStatus === Company::ENRICHMENT_HUNTER_EMPTY,
            $skipStatus,
        );
    }

    public function allowsEnrichment(): bool
    {
        return $this->skipStatus === null && ! $this->alreadyHunterEmpty;
    }

    public function isLowScore(): bool
    {
        return $this->skipStatus === Company::ENRICHMENT_SKIPPED_LOW_SCORE;
    }
}

final readonly class AutomaticEnrichmentRules
{
    public function __construct(
        public bool $autoEnrich,
        public int $minScore,
    ) {}
}
