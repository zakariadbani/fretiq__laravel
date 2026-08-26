<?php

namespace Tests\Unit;

use App\Models\CampaignRun;
use Tests\TestCase;

class CampaignRunCanResyncZohoWaveTest extends TestCase
{
    private function makeRun(array $attributes): CampaignRun
    {
        return new CampaignRun(array_merge([
            'campaign_id' => 1,
            'occurrence_key' => 'sequence-wave-000001',
            'run_at' => now(),
            'status' => 'failed',
            'zoho_campaign_key' => null,
            'driver_ref' => null,
        ], $attributes));
    }

    public function test_failed_with_blank_key_and_wave_key_is_resyncable(): void
    {
        $this->assertTrue($this->makeRun([])->canResyncZohoWave());
    }

    public function test_failed_with_driver_ref_uncertain_but_key_set_is_not_resyncable(): void
    {
        $run = $this->makeRun([
            'driver_ref' => 'zoho-send-uncertain',
            'zoho_campaign_key' => 'campaign-existing',
        ]);

        $this->assertFalse($run->canResyncZohoWave());
    }

    public function test_sent_status_is_not_resyncable(): void
    {
        $this->assertFalse($this->makeRun(['status' => 'sent'])->canResyncZohoWave());
    }

    public function test_canceled_status_is_not_resyncable(): void
    {
        $this->assertFalse($this->makeRun(['status' => 'canceled'])->canResyncZohoWave());
    }

    public function test_non_sequence_wave_occurrence_key_is_not_resyncable(): void
    {
        $this->assertFalse($this->makeRun(['occurrence_key' => 'manual-xyz'])->canResyncZohoWave());
    }

    public function test_prepared_status_with_blank_key_is_resyncable(): void
    {
        $this->assertTrue($this->makeRun(['status' => 'prepared'])->canResyncZohoWave());
    }

    /** @dataProvider recoverableDriverRefsProvider */
    public function test_recoverable_driver_ref_with_persisted_key_is_resyncable(string $driverRef): void
    {
        $run = $this->makeRun([
            'driver_ref' => $driverRef,
            'zoho_campaign_key' => 'campaign-existing',
        ]);

        $this->assertTrue($run->canResyncZohoWave());
    }

    public static function recoverableDriverRefsProvider(): array
    {
        return [
            'zoho-wave-failed' => ['zoho-wave-failed'],
            'zoho-send-failed' => ['zoho-send-failed'],
            'zoho-wave-pending' => ['zoho-wave-pending'],
        ];
    }

    /** @dataProvider ambiguousDriverRefsProvider */
    public function test_ambiguous_driver_ref_with_blank_key_is_not_resyncable(string $driverRef): void
    {
        $run = $this->makeRun([
            'driver_ref' => $driverRef,
            'zoho_campaign_key' => null,
        ]);

        $this->assertFalse($run->canResyncZohoWave());
    }

    public static function ambiguousDriverRefsProvider(): array
    {
        return [
            'zoho-send-attempted' => ['zoho-send-attempted'],
            'zoho-send-uncertain' => ['zoho-send-uncertain'],
        ];
    }
}
