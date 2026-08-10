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
            'Secteur_Activit' => 'Freight',
            'Lead_Source' => 'Salon',
            'Custom_Null__c' => null,
        ], ['seen_at' => '2026-08-09T10:00:00+00:00', 'schema_hash' => 'schema-v1']);

        $this->assertSame('lead-100', $mapped['zoho_id']);
        $this->assertSame('Ada', $mapped['first_name']);
        $this->assertSame('Lovelace', $mapped['last_name']);
        $this->assertSame('ada@example.test', $mapped['email']);
        $this->assertArrayNotHasKey('lead_source', $mapped);
        $this->assertSame('Salon', $mapped['raw_payload']['Lead_Source']);
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

    public function test_it_uses_verified_custom_keys_and_strict_values(): void
    {
        $mapped = (new LeadMapper)->map([
            'id' => 'lead-custom', 'Secteur_Activit' => 'Freight', 'Converted_Account' => ['id' => 'account-1'],
            'Converted_Contact' => ['id' => 'contact-1'], 'Tag' => ['name' => 'VIP', 'id' => 'ignored'],
            'Email_Opt_Out' => 'yes', 'Mobile' => '06', 'Phone' => '01',
        ], ['seen_at' => '2026-08-09T10:00:00+00:00']);

        $this->assertSame('Freight', $mapped['industry']);
        $this->assertSame('account-1', $mapped['account_zoho_id']);
        $this->assertSame('contact-1', $mapped['contact_zoho_id']);
        $this->assertSame(['VIP'], $mapped['tags']);
        $this->assertNull($mapped['email_opt_out']);
        $this->assertSame('01', $mapped['phone']);
        $this->assertSame('06', $mapped['mobile']);
    }
}
