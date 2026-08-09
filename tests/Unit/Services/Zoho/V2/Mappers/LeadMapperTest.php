<?php

namespace Tests\Unit\Services\Zoho\V2\Mappers;

use App\Services\Zoho\V2\Mappers\LeadMapper;
use Tests\TestCase;

class LeadMapperTest extends TestCase
{
    public function test_it_preserves_a_complete_lead_payload_and_promotes_business_fields(): void
    {
        $mapper = new LeadMapper;

        $mapped = $mapper->map([
            'id' => 'lead-100',
            'First_Name' => 'Ada',
            'Last_Name' => 'Lovelace',
            'Email' => 'ada@example.test',
            'Phone' => '+33102030405',
            'Industry' => 'Freight',
            'Lead_Source' => 'Salon',
            'Custom_Null__c' => null,
        ], ['seen_at' => '2026-08-09T10:00:00+00:00', 'schema_hash' => 'schema-v1']);

        $this->assertSame('lead-100', $mapped['zoho_id']);
        $this->assertSame('Ada', $mapped['first_name']);
        $this->assertSame('Lovelace', $mapped['last_name']);
        $this->assertSame('ada@example.test', $mapped['email']);
        $this->assertSame('Salon', $mapped['lead_source']);
        $this->assertSame('ada@example.test', $mapped['normalized_email']);
        $this->assertSame('+33102030405', $mapped['phone']);
        $this->assertSame('Freight', $mapped['industry']);
        $this->assertArrayHasKey('Custom_Null__c', $mapped['raw_payload']);
        $this->assertNull($mapped['raw_payload']['Custom_Null__c']);
        $this->assertSame('schema-v1', $mapped['field_schema_hash']);
    }

    public function test_it_uses_the_sync_batch_contract_not_the_retired_sync_run_contract(): void
    {
        $mapped = (new LeadMapper)->map(['id' => 'lead-101'], [
            'seen_at' => '2026-08-09T10:00:00+00:00',
            'sync_batch_id' => 77,
        ]);

        $this->assertSame(77, $mapped['sync_batch_id']);
        $this->assertArrayNotHasKey('sync_run_id', $mapped);
    }

    public function test_it_keeps_missing_modified_time_and_null_email_null(): void
    {
        $mapped = (new LeadMapper)->map(['id' => 'lead-102', 'Email' => null], ['seen_at' => '2026-08-09T10:00:00+00:00']);

        $this->assertNull($mapped['zoho_modified_at']);
        $this->assertNull($mapped['normalized_email']);
    }
}
