<?php

namespace Tests\Unit\Services\Prospecting;

use App\Services\Prospecting\HunterVerificationStatusNormalizer;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HunterVerificationStatusNormalizerTest extends TestCase
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

    public function test_status_wins_and_legacy_result_is_normalized(): void
    {
        $service = new HunterVerificationStatusNormalizer;

        $this->assertSame('valid', $service->normalize([
            'verification' => ['status' => 'valid', 'result' => 'undeliverable'],
        ]));
        $this->assertSame('valid', $service->normalize([
            'verification' => ['status' => ' ', 'result' => 'deliverable'],
        ]));
        $this->assertSame('accept_all', $service->normalize([
            'verification' => ['result' => 'risky'],
        ]));
        $this->assertSame('invalid', $service->normalize([
            'verification' => ['result' => 'undeliverable'],
        ]));
        $this->assertSame('missing', $service->normalize([
            'verification' => ['status' => 'missing'],
        ]));
        $this->assertSame('unknown', $service->normalize([
            'verification' => ['status' => 'unknown'],
        ]));
        $this->assertSame('disposable', $service->normalize([
            'verification' => ['status' => 'disposable'],
        ]));
        $this->assertSame('webmail', $service->normalize([
            'verification' => ['status' => 'WEBMAIL'],
        ]));
    }

    public function test_unsupported_nonblank_status_does_not_fall_back_to_legacy_result(): void
    {
        $service = new HunterVerificationStatusNormalizer;

        $this->assertNull($service->normalize([
            'verification' => ['status' => 'unsupported', 'result' => 'deliverable'],
        ]));
    }

    #[DataProvider('missingOrUnsupportedVerification')]
    public function test_missing_or_unsupported_verification_is_not_invented(array $payload): void
    {
        $this->assertNull((new HunterVerificationStatusNormalizer)->normalize($payload));
    }

    public static function missingOrUnsupportedVerification(): array
    {
        return [
            'missing verification' => [[]],
            'missing status and result' => [['verification' => []]],
            'unsupported legacy result' => [['verification' => ['result' => 'maybe']]],
            'non scalar status' => [['verification' => ['status' => ['valid']]]],
        ];
    }

    public function test_checked_at_parses_valid_dates_without_throwing_on_bad_input(): void
    {
        $service = new HunterVerificationStatusNormalizer;

        $this->assertSame(
            '2026-08-01T12:34:56+00:00',
            $service->checkedAt([
                'verification' => ['date' => '2026-08-01T12:34:56Z'],
            ])?->toIso8601String(),
        );
        $this->assertNull($service->checkedAt([
            'verification' => ['date' => 'not-a-date'],
        ]));
        $this->assertNull($service->checkedAt([
            'verification' => ['date' => ['2026-08-01']],
        ]));
        $this->assertNull($service->checkedAt(['verification' => ['date' => ' ']]));
    }
}
