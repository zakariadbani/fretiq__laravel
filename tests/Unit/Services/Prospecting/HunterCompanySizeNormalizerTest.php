<?php

namespace Tests\Unit\Services\Prospecting;

use App\Data\Prospecting\RecoveryPlan;
use App\Data\Prospecting\RecoveryResult;
use App\Models\ProspectBatch;
use App\Services\Prospecting\HunterCompanySizeNormalizer;
use Error;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HunterCompanySizeNormalizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    #[DataProvider('hunterSizes')]
    public function test_hunter_ranges_map_to_existing_buckets(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, (new HunterCompanySizeNormalizer)->normalize($input));
    }

    public static function hunterSizes(): array
    {
        return [
            '1-10' => ['1-10', '1-10'],
            '11-50' => ['11-50', '11-50'],
            '51-250' => ['51-250', '51-200'],
            '251-1K' => ['251-1K', '201-500'],
            '1K-5K' => ['1K-5K', '500+'],
            '5K-10K' => ['5K-10K', '500+'],
            '10K-50K' => ['10K-50K', '500+'],
            '50K-100K' => ['50K-100K', '500+'],
            '100K+' => ['100K+', '500+'],
            'numeric 1' => [1, '1-10'],
            'numeric 10' => [10, '1-10'],
            'numeric 11' => [11, '11-50'],
            'numeric 50' => [50, '11-50'],
            'numeric 51' => [51, '51-200'],
            'numeric 200' => [200, '51-200'],
            'numeric 201' => [201, '201-500'],
            'numeric 500' => [500, '201-500'],
            'numeric 501' => [501, '500+'],
            'numeric zero' => [0, null],
            'numeric fractional' => [10.5, null],
            'unexpected' => ['unexpected', null],
            'null' => [null, null],
            'array' => [['1-10'], null],
        ];
    }

    #[DataProvider('unicodeSpacingVariants')]
    public function test_unicode_dashes_and_spaces_are_normalized(string $input, string $expected): void
    {
        $this->assertSame($expected, (new HunterCompanySizeNormalizer)->normalize($input));
    }

    public static function unicodeSpacingVariants(): array
    {
        return [
            'en dash' => ['51–250', '51-200'],
            'em dash' => ['251—1K', '201-500'],
            'nonbreaking spaces' => ["1K\u{00A0}-\u{00A0}5K", '500+'],
            'narrow nonbreaking spaces' => ["11\u{202F}–\u{202F}50", '11-50'],
            'outer and inner spaces' => ['  5K - 10K  ', '500+'],
            'lowercase suffix' => ['10k–50k', '500+'],
        ];
    }

    public function test_recovery_plan_is_an_immutable_manifest(): void
    {
        $plan = new RecoveryPlan(
            fingerprint: str_repeat('a', 64),
            verificationChanges: [['contact_id' => 1]],
            companyChanges: [['company_id' => 2]],
            discoveredContacts: [['email' => 'role@example.test']],
            snapshotItems: [['host' => 'example.test']],
            counts: ['verification_statuses' => 1],
            sourceEvidenceHashes: ['payload' => str_repeat('b', 64)],
        );

        $this->assertSame(str_repeat('a', 64), $plan->fingerprint);
        $this->assertSame([['contact_id' => 1]], $plan->verificationChanges);
        $this->assertSame([['company_id' => 2]], $plan->companyChanges);
        $this->assertSame([['email' => 'role@example.test']], $plan->discoveredContacts);
        $this->assertSame([['host' => 'example.test']], $plan->snapshotItems);
        $this->assertSame(['verification_statuses' => 1], $plan->counts);
        $this->assertSame(['payload' => str_repeat('b', 64)], $plan->sourceEvidenceHashes);

        $this->expectException(Error::class);
        $plan->fingerprint = str_repeat('c', 64);
    }

    public function test_recovery_result_is_immutable_and_keeps_the_batch_identity(): void
    {
        $batch = new ProspectBatch(['source_type' => 'recovery']);
        $result = new RecoveryResult($batch, ['company_changes' => 2], true);

        $this->assertSame($batch, $result->batch);
        $this->assertSame(['company_changes' => 2], $result->counts);
        $this->assertTrue($result->reused);

        $this->expectException(Error::class);
        $result->reused = false;
    }
}
