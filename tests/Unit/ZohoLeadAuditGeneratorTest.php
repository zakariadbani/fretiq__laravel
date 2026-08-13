<?php

declare(strict_types=1);

namespace Tests\Unit;

use ReflectionMethod;
use Tests\TestCase;

final class ZohoLeadAuditGeneratorTest extends TestCase
{
    public function test_invalid_local_email_verification_hard_suppresses_report_lead(): void
    {
        $leadId = '5309063000000000001';
        $payload = [
            'id' => $leadId,
            'Full_Name' => 'Policy Fixture',
            'Company' => 'Fixture Company',
            'Email' => 'policy.fixture@example.test',
            'Lead_Status' => 'PROSPECT',
            'Converted__s' => false,
            'Email_Opt_Out' => false,
        ];
        $lead = $this->buildLead($payload, [
            'localContactsByEmail' => ['policy.fixture@example.test' => [1]],
            'localContacts' => [1 => [
                'status' => 'active',
                'email_verification_status' => 'invalid',
                'legal_basis' => 'legitimate_interest',
                'source_url' => 'https://example.test/source',
                'deleted_at' => null,
            ]],
        ]);

        $this->assertTrue($lead['suppressed']);
        $this->assertSame('hard_suppression', $lead['category']);
        $this->assertSame(
            'Local email verification marks the address invalid or undeliverable.',
            $lead['suppression_reason'],
        );
    }

    public function test_stale_open_task_does_not_trigger_immediate_human_follow_up(): void
    {
        $leadId = '5309063000000000002';
        $activityId = 'activity:task:5309063000000000999';
        $staleDate = now('Africa/Casablanca')->subDays(400)->toIso8601String();
        $payload = [
            'id' => $leadId,
            'Full_Name' => 'Stale Task Fixture',
            'Company' => 'Fixture Company',
            'Email' => 'stale.task@example.test',
            'Lead_Status' => 'PROSPECT',
            'Converted__s' => false,
            'Email_Opt_Out' => false,
        ];
        $lead = $this->buildLead($payload, [
            'activities' => [$activityId => [
                'kind' => 'task',
                'date' => $staleDate,
                'due' => $staleDate,
                'title' => 'Historical task',
                'status' => 'Not Started',
                'detail' => null,
            ]],
            'activityIndex' => [$leadId => [$activityId]],
        ]);

        $this->assertNull($lead['open_task_due']);
        $this->assertSame('warm_prospect', $lead['category']);
    }

    public function test_contact_activity_scope_wins_over_account_context(): void
    {
        $leadId = '5309063000000000003';
        $contactId = '5309063000000000300';
        $accountId = '5309063000000000400';
        $activityId = 'activity:call:5309063000000000500';
        $activityDate = now('Africa/Casablanca')->subDays(60)->toIso8601String();
        $payload = [
            'id' => $leadId,
            'Full_Name' => 'Scope Fixture',
            'Company' => 'Fixture Company',
            'Email' => 'scope.fixture@example.test',
            'Lead_Status' => 'PROSPECT',
            'Converted__s' => true,
            'Converted_Contact' => ['id' => $contactId],
            'Converted_Account' => ['id' => $accountId],
            'Email_Opt_Out' => false,
        ];
        $lead = $this->buildLead($payload, [
            'activities' => [$activityId => [
                'kind' => 'call',
                'date' => $activityDate,
                'due' => null,
                'title' => 'Contact and account call',
                'status' => 'Held',
                'detail' => null,
            ]],
            'activityIndex' => [
                $contactId => [$activityId],
                $accountId => [$activityId],
            ],
        ]);

        $activity = collect($lead['timeline'])->firstWhere('kind', 'call');

        $this->assertSame(1, $lead['direct_activity_count']);
        $this->assertSame(0, $lead['context_activity_count']);
        $this->assertSame('converted contact', $activity['scope']);
    }

    public function test_converted_lead_with_only_stale_quote_context_is_reactivation(): void
    {
        $leadId = '5309063000000000004';
        $accountId = '5309063000000000600';
        $quoteId = '5309063000000000700';
        $staleDate = now('Africa/Casablanca')->subDays(400)->toIso8601String();
        $payload = [
            'id' => $leadId,
            'Full_Name' => 'Dormant Quote Fixture',
            'Company' => 'Fixture Company',
            'Email' => 'dormant.quote@example.test',
            'Lead_Status' => 'PROSPECT',
            'Converted__s' => true,
            'Converted_Account' => ['id' => $accountId],
            'Email_Opt_Out' => false,
        ];
        $lead = $this->buildLead($payload, [
            'quotes' => [$quoteId => [
                'date' => $staleDate,
                'last_activity_at' => null,
                'transport' => 'Road',
                'number' => 'Q-OLD',
                'subject' => 'Historical quote',
                'follow_up_status' => null,
                'status' => 'Closed',
                'origin' => 'Casablanca',
                'destination' => 'Paris',
                'valid_till' => $staleDate,
            ]],
            'quotesByAccount' => [$accountId => [$quoteId]],
        ]);

        $this->assertSame(1, $lead['quote_count']);
        $this->assertGreaterThan(180, $lead['recency_days']);
        $this->assertSame('converted_reactivation', $lead['category']);
    }

    private function buildLead(array $payload, array $universeOverrides = []): array
    {
        require_once base_path('scripts/marketing/ZohoLeadAuditGenerator.php');

        $leadId = (string) $payload['id'];
        $universe = array_replace_recursive([
            'localLeads' => [],
            'localMirrorSyncedAt' => null,
            'owners' => [],
            'accounts' => [],
            'zohoContacts' => [],
            'activities' => [],
            'activityIndex' => [],
            'actions' => [],
            'actionIndex' => [],
            'deals' => [],
            'dealsByAccount' => [],
            'dealsByContact' => [],
            'dealHistory' => [],
            'quotes' => [],
            'quotesByDeal' => [],
            'quotesByAccount' => [],
            'quotesByContact' => [],
            'quoteHistory' => [],
            'localContactsByEmail' => ['policy.fixture@example.test' => [1]],
            'localContacts' => [1 => [
                'status' => 'active',
                'email_verification_status' => 'invalid',
                'legal_basis' => 'legitimate_interest',
                'source_url' => 'https://example.test/source',
                'deleted_at' => null,
            ]],
            'campaignRecipients' => [],
            'sequenceSends' => [],
            'inbox' => ['by_contact' => [], 'by_email' => []],
            'demandes' => [],
            'suppressions' => ['by_contact' => [], 'by_email' => []],
        ], $universeOverrides);

        $generator = new \ZohoLeadAuditGenerator([$leadId => $payload], [], true);
        $buildLead = new ReflectionMethod($generator, 'buildLead');
        $email = mb_strtolower(trim((string) ($payload['Email'] ?? '')));

        return $buildLead->invoke($generator, $leadId, [], $payload, $universe, [
            $email => 1,
        ]);
    }
}
