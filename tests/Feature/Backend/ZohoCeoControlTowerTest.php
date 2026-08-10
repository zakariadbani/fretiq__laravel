<?php

namespace Tests\Feature\Backend;

use App\Models\Contact;
use App\Models\User;
use App\Services\Zoho\V2\Marketing\MarketingAnalyticsQuery;
use App\Services\Zoho\V2\Marketing\MarketingPeriod;
use App\Services\Zoho\V2\Marketing\MarketingScope;
use App\Services\Zoho\V2\Marketing\ZohoCeoControlTower;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZohoCeoControlTowerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ceo_control_tower_uses_quote_date_and_proven_activity(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('account-a', ['name' => 'Compte Test', 'phone' => '0100000000']);
            $this->owner('owner-a', 'Commercial Test');
            $this->quote('quote-in-period', [
                'quote_date' => '2026-08-08',
                'zoho_created_at' => '2024-01-01 00:00:00',
                'follow_up_status' => null,
                'valid_till' => '2026-08-12',
                'account_zoho_id' => 'account-a',
            ]);
            $this->quote('quote-out-period', [
                'quote_date' => '2024-01-01',
                'zoho_created_at' => '2026-08-08 00:00:00',
                'account_zoho_id' => 'account-a',
            ]);
            $this->activity('task-completed', [
                'activity_type' => 'task',
                'status' => 'Terminé',
                'parent_zoho_id' => 'quote-in-period',
            ]);
            $this->activity('meeting', ['activity_type' => 'meeting', 'start_at' => '2026-08-09 10:00:00']);

            $payload = $this->analyse();

            $this->assertSame(1, $payload['metrics']['quotes']);
            $this->assertSame(1, $payload['metrics']['missing_status']);
            $this->assertSame(1, $payload['metrics']['completed_tasks']);
            $this->assertSame(1, $payload['metrics']['meetings']);
            $this->assertSame(2, $payload['metrics']['proven_human_follow_up']);
            $this->assertSame('quote_date', $payload['meta']['quote_period_basis']);
            $this->assertSame('zoho_created_at', $payload['meta']['task_creation_basis']);
            $this->assertSame('Commercial Test', $payload['owners'][0]['owner']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ceo_control_tower_uses_utc_boundaries_for_task_creation(): void
    {
        $period = MarketingPeriod::fromInput([
            'preset' => 'custom',
            'from' => '2026-08-10',
            'to' => '2026-08-10',
        ]);
        $this->activity('utc-in', [
            'activity_type' => 'task',
            'status' => 'Not Started',
            'zoho_created_at' => '2026-08-09 22:30:00',
            'activity_at' => '2026-09-09 22:30:00',
            'due_at' => '2026-09-09 22:30:00',
        ]);
        $this->activity('utc-out', [
            'activity_type' => 'task',
            'status' => 'Completed',
            'zoho_created_at' => '2026-08-10 22:30:00',
            'activity_at' => '2026-08-10 22:30:00',
        ]);

        $payload = $this->analyse($period);

        $this->assertSame(1, $payload['metrics']['tasks_created']);
        $this->assertSame(1, $payload['metrics']['not_started_tasks']);
    }

    public function test_contactability_priority_scope_and_masking_are_enforced(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('account-reachable', ['owner_zoho_id' => 'owner-a', 'name' => 'Compte Autorisé']);
            $this->account('account-optout', ['owner_zoho_id' => 'owner-a', 'name' => 'Compte Optout']);
            $this->account('account-secret', ['owner_zoho_id' => 'owner-b', 'name' => 'Compte Secret']);
            $this->zohoContact('contact-reachable', [
                'owner_zoho_id' => 'owner-a',
                'account_zoho_id' => 'account-reachable',
                'full_name' => 'Alice Confidentiel',
                'phone' => '0612345678',
                'email' => 'alice@example.test',
            ]);
            $this->zohoContact('contact-optout', [
                'owner_zoho_id' => 'owner-a',
                'account_zoho_id' => 'account-optout',
                'full_name' => 'Olivia Optout',
                'email' => 'optout@example.test',
                'email_opt_out' => true,
            ]);
            $this->zohoContact('contact-secret', [
                'owner_zoho_id' => 'owner-b',
                'account_zoho_id' => 'account-secret',
                'full_name' => 'Secret Person',
                'phone' => '0699999999',
            ]);
            foreach ([
                ['reachable', 'account-reachable', 'contact-reachable', 'owner-a'],
                ['optout', 'account-optout', 'contact-optout', 'owner-a'],
                ['secret', 'account-secret', 'contact-secret', 'owner-b'],
            ] as [$suffix, $accountId, $contactId, $ownerId]) {
                $this->quote('quote-'.$suffix, [
                    'owner_zoho_id' => $ownerId,
                    'quote_date' => '2026-08-09',
                    'valid_till' => '2026-08-10',
                    'account_zoho_id' => $accountId,
                    'contact_zoho_id' => $contactId,
                ]);
            }

            $payload = $this->analyse(scope: new MarketingScope(null, ['owner-a'], true, false));
            $queue = collect($payload['queue']['items'])->keyBy('account');
            $serialized = json_encode($payload['queue'], JSON_THROW_ON_ERROR);

            $this->assertSame(2, $payload['metrics']['quotes']);
            $this->assertSame('P1', $queue['Compte Autorisé']['priority']);
            $this->assertSame('Téléphone contact masqué', $queue['Compte Autorisé']['channel']);
            $this->assertSame('Contact nommé · identité masquée', $queue['Compte Autorisé']['contact']);
            $this->assertSame('enrichment', $queue['Compte Optout']['priority']);
            $this->assertSame('Indisponible', $queue['Compte Optout']['channel']);
            $this->assertStringNotContainsString('Alice Confidentiel', $serialized);
            $this->assertStringNotContainsString('0612345678', $serialized);
            $this->assertStringNotContainsString('alice@example.test', $serialized);
            $this->assertStringNotContainsString('Compte Secret', $serialized);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_repeated_quote_requires_no_deal_decision_or_proven_follow_up(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('repeat', ['phone' => '0100000000']);
            $this->quote('repeat-a', [
                'quote_date' => '2026-08-08',
                'valid_till' => '2026-09-10',
                'account_zoho_id' => 'repeat',
            ]);
            $this->quote('repeat-b', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-09-11',
                'account_zoho_id' => 'repeat',
            ]);

            $before = $this->analyse();
            $this->assertSame(1, $before['queue']['total_by_priority']['P2']);

            $this->activity('repeat-call', [
                'activity_type' => 'call',
                'parent_zoho_id' => 'repeat-a',
                'activity_at' => '2026-08-09 09:00:00',
            ]);
            $after = $this->analyse();
            $this->assertSame(0, $after['queue']['total_by_priority']['P2']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_future_same_day_human_activities_do_not_count_or_suppress_repeated_quote_p2(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('future-proof', ['phone' => '0100000000']);
            foreach (['a', 'b'] as $suffix) {
                $this->quote('future-proof-'.$suffix, [
                    'quote_date' => $suffix === 'a' ? '2026-08-08' : '2026-08-09',
                    'valid_till' => '2026-09-10',
                    'account_zoho_id' => 'future-proof',
                ]);
            }
            $this->activity('future-call', [
                'activity_type' => 'call',
                'parent_zoho_id' => 'future-proof-a',
                'activity_at' => '2026-08-10 16:00:00',
            ]);
            $this->activity('future-meeting', [
                'activity_type' => 'meeting',
                'parent_zoho_id' => 'future-proof',
                'activity_at' => '2026-08-10 15:00:00',
                'start_at' => '2026-08-10 17:00:00',
            ]);
            $this->activity('future-note', [
                'activity_type' => 'note',
                'contact_zoho_id' => null,
                'parent_zoho_id' => 'future-proof-b',
                'activity_at' => '2026-08-10 18:00:00',
            ]);

            $payload = $this->analyse();
            $p2 = collect($payload['queue']['items'])->firstWhere('priority', 'P2');

            $this->assertSame(0, $payload['metrics']['calls']);
            $this->assertSame(0, $payload['metrics']['meetings']);
            $this->assertSame(0, $payload['metrics']['notes']);
            $this->assertSame(0, $payload['metrics']['proven_human_follow_up']);
            $currentMonth = collect($payload['monthly'])->firstWhere('month', '2026-08');
            $this->assertSame(0, $currentMonth['meetings']);
            $this->assertSame(0, $currentMonth['calls']);
            $this->assertSame(0, $currentMonth['notes']);
            $this->assertSame(1, $payload['queue']['total_by_priority']['P2']);
            $this->assertNotNull($p2);
            $this->assertSame('Indisponible', $p2['last_proven_action']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_same_account_deal_explicit_deal_and_current_decision_each_suppress_repeated_quote_p2(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['account-deal', 'explicit-deal', 'decision'] as $accountId) {
                $this->account($accountId, ['phone' => '0100000000']);
                foreach (['a', 'b'] as $suffix) {
                    $this->quote($accountId.'-'.$suffix, [
                        'quote_date' => $suffix === 'a' ? '2026-08-08' : '2026-08-09',
                        'valid_till' => '2026-09-10',
                        'account_zoho_id' => $accountId,
                        'deal_zoho_id' => $accountId === 'explicit-deal' && $suffix === 'a' ? 'external-deal' : null,
                        'follow_up_status' => $accountId === 'decision' && $suffix === 'a' ? 'Affaire gagnée' : null,
                    ]);
                }
            }
            $this->deal('same-account-deal', ['account_zoho_id' => 'account-deal']);

            $payload = $this->analyse();

            $this->assertSame(0, $payload['queue']['total_by_priority']['P2']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_decision_cards_count_deduplicated_accounts_containing_each_p1_or_p2_signal(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('overlap-p1-p2', ['name' => 'Compte double décision', 'phone' => '0102030405']);
            $this->quote('overlap-p1-p2-urgent', [
                'quote_date' => '2026-08-08',
                'valid_till' => '2026-08-09',
                'account_zoho_id' => 'overlap-p1-p2',
            ]);
            $this->quote('overlap-p1-p2-repeat', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-09-10',
                'account_zoho_id' => 'overlap-p1-p2',
            ]);

            $payload = $this->analyse();
            $row = collect($payload['queue']['items'])->firstWhere('account', 'Compte double décision');

            $this->assertSame('P1', $row['priority']);
            $this->assertContains('P1', $row['signals']);
            $this->assertContains('P2', $row['signals']);
            $this->assertSame(1, $payload['queue']['total_by_priority']['P1']);
            $this->assertSame(0, $payload['queue']['total_by_priority']['P2']);
            $this->assertSame(1, $payload['decision_cards']['P1']['value']);
            $this->assertSame(1, $payload['decision_cards']['P2']['value']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_deterministic_campaign_open_without_reply_is_a_soft_p3_signal(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('account-p3', ['name' => 'Compte P3']);
            $recipientId = $this->campaignSignal('p3', [
                'account_zoho_id' => 'account-p3',
                'full_name' => 'Personne P3',
                'email' => 'p3@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);

            $payload = $this->analyse();
            $p3 = collect($payload['queue']['items'])->firstWhere('priority', 'P3');
            $serialized = json_encode($p3, JSON_THROW_ON_ERROR);

            $this->assertSame(1, $payload['decision_cards']['P3']['value']);
            $this->assertNotNull($p3);
            $this->assertStringContainsString('signal doux', implode(' ', $p3['reasons']));
            $this->assertStringNotContainsString('lead chaud', mb_strtolower($p3['recommended_action']));
            $this->assertStringNotContainsString('Personne P3', $serialized);
            $this->assertStringNotContainsString('p3@example.test', $serialized);

            DB::table('campaign_recipients')->where('id', $recipientId)->update([
                'status' => 'replied',
                'replied_at' => '2026-08-09 11:00:00',
            ]);
            $replied = $this->analyse();
            $this->assertSame(0, $replied['decision_cards']['P3']['value']);
            $this->assertSame(0, $replied['queue']['total_by_priority']['P3']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_campaign_signal_remains_actionable_from_fresh_named_contact_when_account_evidence_is_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'deals', 'tasks', 'events', 'calls', 'notes', 'contacts'] as $module) {
                $this->syncLog($module);
            }
            $this->campaignSignal('missing-account-evidence', [
                'account_zoho_id' => 'account-not-in-mirror',
                'full_name' => 'Contact avec signal',
                'email' => 'missing-account-evidence@example.test',
            ]);

            $payload = $this->analyse(seedFreshness: false);

            $this->assertFalse($payload['freshness']['modules']['accounts']['available']);
            $this->assertTrue($payload['queue']['availability_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['P3']);
            $this->assertSame(1, $payload['decision_cards']['P3']['value']);
            $this->assertNotNull(collect($payload['queue']['items'])->firstWhere('priority', 'P3'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_campaign_signal_with_unknown_account_channel_remains_visible_but_not_reachable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'deals', 'tasks', 'events', 'calls', 'notes', 'contacts'] as $module) {
                $this->syncLog($module);
            }
            $this->campaignSignal('unknown-account-channel', [
                'account_zoho_id' => 'unknown-account-channel-id',
                'full_name' => 'Contact sans canal',
                'email' => null,
                'phone' => null,
                'mobile' => null,
            ]);

            $payload = $this->analyse(seedFreshness: false);
            $row = collect($payload['queue']['items'])->firstWhere('priority', 'P3');

            $this->assertFalse($payload['freshness']['modules']['accounts']['available']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['P3']);
            $this->assertSame(1, $payload['decision_cards']['P3']['value']);
            $this->assertNotNull($row);
            $this->assertSame('Indisponible', $row['channel']);
            $this->assertStringContainsString('synchronisation', mb_strtolower($row['recommended_action']));
            $this->assertSame(0, $payload['briefing']['reachable_accounts']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_contactability_uses_all_named_contacts_and_blocks_every_known_email_restriction(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('multi', ['name' => 'Compte Multi']);
            $this->zohoContact('multi-empty', [
                'account_zoho_id' => 'multi',
                'full_name' => 'Premier Contact',
            ]);
            $this->zohoContact('multi-mobile', [
                'account_zoho_id' => 'multi',
                'full_name' => 'Second Contact',
                'mobile' => '0611111111',
            ]);
            $this->quote('multi-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-10',
                'account_zoho_id' => 'multi',
                'contact_zoho_id' => 'multi-empty',
            ]);

            $this->account('suppressed', ['name' => 'Compte Supprimé']);
            $this->zohoContact('suppressed-contact', [
                'account_zoho_id' => 'suppressed',
                'full_name' => 'Contact Supprimé',
                'email' => 'blocked@example.test',
            ]);
            DB::table('suppressions')->insert([
                'email' => 'blocked@example.test',
                'reason' => 'manual',
                'source' => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->quote('suppressed-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-10',
                'account_zoho_id' => 'suppressed',
                'contact_zoho_id' => 'suppressed-contact',
            ]);

            $this->account('zoho-unsubscribed', ['name' => 'Compte Désabonné']);
            $this->zohoContact('zoho-unsubscribed-contact', [
                'account_zoho_id' => 'zoho-unsubscribed',
                'full_name' => 'Contact Désabonné',
                'email' => 'unsubscribed@example.test',
                'unsubscribed_at' => '2026-08-01 08:00:00',
            ]);
            $this->quote('zoho-unsubscribed-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-10',
                'account_zoho_id' => 'zoho-unsubscribed',
                'contact_zoho_id' => 'zoho-unsubscribed-contact',
            ]);

            $this->account('campaign-unsubscribed', ['name' => 'Compte Désabonné Campagne']);
            $recipientId = $this->campaignSignal('campaign-unsubscribed', [
                'account_zoho_id' => 'campaign-unsubscribed',
                'full_name' => 'Contact Désabonné Campagne',
                'email' => 'campaign-unsubscribed@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);
            DB::table('campaign_recipients')->where('id', $recipientId)->update(['status' => 'unsubscribed']);
            $this->quote('campaign-unsubscribed-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-10',
                'account_zoho_id' => 'campaign-unsubscribed',
                'contact_zoho_id' => 'zoho-campaign-unsubscribed',
            ]);

            $queue = collect($this->analyse()['queue']['items'])->keyBy('account');

            $this->assertSame('P1', $queue['Compte Multi']['priority']);
            $this->assertSame('Téléphone contact masqué', $queue['Compte Multi']['channel']);
            $this->assertSame('enrichment', $queue['Compte Supprimé']['priority']);
            $this->assertSame('enrichment', $queue['Compte Désabonné']['priority']);
            $this->assertSame('enrichment', $queue['Compte Désabonné Campagne']['priority']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_an_old_reply_does_not_hide_a_later_unreplied_message(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('later-open', ['name' => 'Compte Nouvelle Ouverture']);
            $oldRecipientId = $this->campaignSignal('later-open', [
                'account_zoho_id' => 'later-open',
                'full_name' => 'Contact Campagne',
                'email' => 'later-open@example.test',
                'opened_at' => '2026-08-01 10:00:00',
            ]);
            DB::table('campaign_recipients')->where('id', $oldRecipientId)->update([
                'status' => 'replied',
                'replied_at' => '2026-08-01 11:00:00',
            ]);
            $oldRecipient = DB::table('campaign_recipients')->where('id', $oldRecipientId)->first();
            $oldRun = DB::table('campaign_runs')->where('id', $oldRecipient->campaign_run_id)->first();
            $newRunId = DB::table('campaign_runs')->insertGetId([
                'campaign_id' => $oldRun->campaign_id,
                'occurrence_key' => 'occurrence-later-open-2',
                'run_at' => '2026-08-09 08:00:00',
                'status' => 'sent',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('campaign_recipients')->insert([
                'campaign_run_id' => $newRunId,
                'contact_id' => $oldRecipient->contact_id,
                'status' => 'opened',
                'sent_at' => '2026-08-09 09:00:00',
                'opened_at' => '2026-08-09 10:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = $this->analyse();

            $this->assertSame(1, $payload['decision_cards']['P3']['value']);
            $this->assertSame(1, $payload['queue']['total_by_priority']['P3']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ambiguous_local_to_zoho_campaign_mapping_is_excluded(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('ambiguous-p3', ['name' => 'Compte Ambigu']);
            $recipientId = $this->campaignSignal('ambiguous-p3', [
                'account_zoho_id' => 'ambiguous-p3',
                'full_name' => 'Contact Ambigu',
                'email' => 'ambiguous@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);
            $localContactId = DB::table('campaign_recipients')->where('id', $recipientId)->value('contact_id');
            $this->zohoContact('zoho-ambiguous-duplicate', [
                'fretiq_contact_id' => $localContactId,
                'account_zoho_id' => 'ambiguous-p3',
                'full_name' => 'Deuxième Mapping',
                'email' => 'ambiguous@example.test',
            ]);

            $payload = $this->analyse();

            $this->assertSame(0, $payload['decision_cards']['P3']['value']);
            $this->assertSame(0, $payload['queue']['total_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['enrichment']);
            $this->assertStringContainsString('ambigu', mb_strtolower($payload['readiness']['identity_links']));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_campaign_mapping_remains_ambiguous_when_the_duplicate_is_outside_owner_and_country_scope(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('scoped-ambiguous-p3', [
                'owner_zoho_id' => 'owner-a',
                'name' => 'Compte ambigu filtré',
            ]);
            $recipientId = $this->campaignSignal('scoped-ambiguous-p3', [
                'owner_zoho_id' => 'owner-a',
                'account_zoho_id' => 'scoped-ambiguous-p3',
                'country' => 'France',
                'full_name' => 'Contact autorisé',
                'email' => 'scoped-ambiguous@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);
            $localContactId = DB::table('campaign_recipients')->where('id', $recipientId)->value('contact_id');
            $this->zohoContact('zoho-scoped-ambiguous-duplicate', [
                'fretiq_contact_id' => $localContactId,
                'owner_zoho_id' => 'owner-b',
                'account_zoho_id' => 'outside-scope-account',
                'country' => 'Maroc',
                'full_name' => 'Contact hors périmètre',
                'email' => 'scoped-ambiguous@example.test',
            ]);

            $payload = $this->analyse(
                scope: new MarketingScope(null, ['owner-a'], true, false),
                filters: ['country' => 'France'],
            );

            $this->assertTrue($payload['queue']['availability_by_priority']['P3']);
            $this->assertSame(0, $payload['decision_cards']['P3']['value']);
            $this->assertSame(0, $payload['queue']['total_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['enrichment']);
            $this->assertStringContainsString('ambigu', mb_strtolower($payload['readiness']['identity_links']));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_scoped_out_campaign_candidate_is_not_a_known_zero_when_contact_freshness_is_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'deals', 'tasks', 'events', 'calls', 'notes', 'accounts'] as $module) {
                $this->syncLog($module);
            }
            $this->campaignSignal('scoped-out-unavailable-contact', [
                'owner_zoho_id' => 'owner-b',
                'account_zoho_id' => 'scoped-out-account',
                'full_name' => 'Contact hors périmètre',
                'email' => 'scoped-out@example.test',
            ]);
            DB::table('zoho_contacts')->where('zoho_id', 'zoho-scoped-out-unavailable-contact')
                ->update(['last_synced_at' => null]);

            $payload = $this->analyse(
                scope: new MarketingScope(null, ['owner-a'], true, false),
                seedFreshness: false,
            );

            $this->assertFalse($payload['freshness']['modules']['contacts']['available']);
            $this->assertFalse($payload['queue']['availability_by_priority']['P3']);
            $this->assertSame('unavailable', $payload['queue']['completeness_by_priority']['P3']);
            $this->assertSame('Indisponible', $payload['decision_cards']['P3']['value']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_account_or_any_account_contact_activity_blocks_repeated_quote_escalation(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('contact-proof', ['phone' => '0100000000']);
            $this->zohoContact('contact-proof-person', [
                'account_zoho_id' => 'contact-proof',
                'full_name' => 'Contact Prouvé',
            ]);
            $this->zohoContact('contact-proof-other', [
                'account_zoho_id' => 'contact-proof',
                'full_name' => 'Autre Contact Prouvé',
            ]);
            foreach (['a', 'b'] as $suffix) {
                $this->quote('contact-proof-'.$suffix, [
                    'quote_date' => '2026-08-0'.($suffix === 'a' ? '8' : '9'),
                    'valid_till' => '2026-09-10',
                    'account_zoho_id' => 'contact-proof',
                    'contact_zoho_id' => 'contact-proof-person',
                ]);
            }
            $this->assertSame(1, $this->analyse()['queue']['total_by_priority']['P2']);

            $this->activity('account-proof-call', [
                'activity_type' => 'call',
                'parent_zoho_id' => 'contact-proof',
                'contact_zoho_id' => null,
                'activity_at' => '2026-08-09 09:00:00',
            ]);
            $this->assertSame(0, $this->analyse()['queue']['total_by_priority']['P2']);

            DB::table('zoho_activities')->where('zoho_id', 'account-proof-call')->delete();
            $this->activity('other-contact-proof-note', [
                'activity_type' => 'note',
                'parent_zoho_id' => null,
                'contact_zoho_id' => 'contact-proof-other',
                'activity_at' => '2026-08-09 09:00:00',
            ]);

            $payload = $this->analyse();

            $this->assertSame(0, $payload['queue']['total_by_priority']['P2']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_stale_deal_ignores_generic_last_activity_and_uses_proven_human_evidence(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('stale-account', ['name' => 'Compte Deal Ancien', 'phone' => '0100000000']);
            $this->deal('stale-deal', [
                'account_zoho_id' => 'stale-account',
                'zoho_created_at' => '2026-01-01 08:00:00',
                'stage_modified_at' => '2026-06-01 08:00:00',
                'last_activity_at' => '2026-08-09 08:00:00',
            ]);

            $before = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte Deal Ancien');
            $this->assertSame('P2', $before['priority']);

            $this->activity('stale-deal-call', [
                'activity_type' => 'call',
                'parent_zoho_id' => 'stale-deal',
                'activity_at' => '2026-08-09 09:00:00',
            ]);
            $after = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte Deal Ancien');
            $this->assertNull($after);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_completed_task_with_unknown_completion_date_prevents_stale_deal_claim_even_with_an_old_call(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('mixed-recency-account', ['name' => 'Compte récence incertaine', 'phone' => '0100000000']);
            $this->quote('mixed-recency-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'mixed-recency-account',
            ]);
            $this->deal('mixed-recency-deal', [
                'account_zoho_id' => 'mixed-recency-account',
                'zoho_created_at' => '2026-01-01 08:00:00',
                'stage_modified_at' => '2026-01-01 08:00:00',
            ]);
            $this->activity('mixed-recency-old-call', [
                'activity_type' => 'call',
                'parent_zoho_id' => 'mixed-recency-deal',
                'activity_at' => '2026-01-15 09:00:00',
            ]);
            $this->activity('mixed-recency-completed-task', [
                'activity_type' => 'task',
                'parent_zoho_id' => 'mixed-recency-deal',
                'status' => 'Completed',
                'activity_at' => null,
            ]);

            $row = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte récence incertaine');

            $this->assertSame('P1', $row['priority']);
            $this->assertContains('P1', $row['signals']);
            $this->assertNotContains('P2', $row['signals']);
            $this->assertSame('Tâche terminée · date de réalisation indisponible', $row['last_proven_action']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_same_priority_earlier_deadline_keeps_matching_quote_context(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('deadline-account', ['name' => 'Compte Échéance', 'phone' => '0100000000']);
            $this->quote('deadline-late', [
                'quote_number' => 'DEVIS-TARDIF',
                'quote_date' => '2026-08-08',
                'valid_till' => '2026-08-16',
                'account_zoho_id' => 'deadline-account',
            ]);
            $this->quote('deadline-early', [
                'quote_number' => 'DEVIS-PROCHE',
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'deadline-account',
            ]);

            $row = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte Échéance');

            $this->assertSame('2026-08-11', $row['deadline']);
            $this->assertStringContainsString('DEVIS-PROCHE', $row['quote_context']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_equal_deadline_uses_a_stable_quote_tie_breaker(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('equal-deadline', ['name' => 'Compte Stable', 'phone' => '0100000000']);
            $this->quote('equal-b', [
                'quote_number' => 'DEVIS-B',
                'quote_date' => '2026-08-08',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'equal-deadline',
            ]);
            $this->quote('equal-a', [
                'quote_number' => 'DEVIS-A',
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'equal-deadline',
            ]);

            $row = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte Stable');

            $this->assertStringContainsString('DEVIS-A', $row['quote_context']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_account_owner_precedes_mixed_quote_and_campaign_owners_deterministically(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach ([
                'owner-account-quotes' => 'Responsable compte devis',
                'owner-account-signals' => 'Responsable compte campagne',
                'owner-a' => 'Commercial A',
                'owner-b' => 'Commercial B',
                'owner-c' => 'Commercial C',
                'owner-z' => 'Commercial Z',
            ] as $ownerId => $ownerName) {
                $this->owner($ownerId, $ownerName);
            }

            $this->account('mixed-quote-owners', [
                'owner_zoho_id' => 'owner-account-quotes',
                'name' => 'Compte devis multi-propriétaires',
                'phone' => '0100000000',
            ]);
            $this->quote('mixed-owner-z', [
                'owner_zoho_id' => 'owner-z',
                'quote_number' => 'DEVIS-Z',
                'quote_date' => '2026-08-08',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'mixed-quote-owners',
            ]);
            $this->quote('mixed-owner-a', [
                'owner_zoho_id' => 'owner-a',
                'quote_number' => 'DEVIS-A',
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'mixed-quote-owners',
            ]);

            $this->account('mixed-signal-owners', [
                'owner_zoho_id' => 'owner-account-signals',
                'name' => 'Compte campagne multi-propriétaires',
            ]);
            $this->campaignSignal('mixed-signal-c', [
                'owner_zoho_id' => 'owner-c',
                'account_zoho_id' => 'mixed-signal-owners',
                'full_name' => 'Contact C',
                'email' => 'mixed-signal-c@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);
            $this->campaignSignal('mixed-signal-b', [
                'owner_zoho_id' => 'owner-b',
                'account_zoho_id' => 'mixed-signal-owners',
                'full_name' => 'Contact B',
                'email' => 'mixed-signal-b@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);

            $rows = collect($this->analyse()['queue']['items'])->keyBy('account');

            $this->assertSame(
                'Responsable compte devis · propriétaires multiples',
                $rows['Compte devis multi-propriétaires']['owner'],
            );
            $this->assertSame(
                'Responsable compte campagne · propriétaires multiples',
                $rows['Compte campagne multi-propriétaires']['owner'],
            );
            $this->assertSame('P1', $rows['Compte devis multi-propriétaires']['priority']);
            $this->assertSame('P3', $rows['Compte campagne multi-propriétaires']['priority']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_freshness_distinguishes_never_synchronized_and_partially_synchronized_mirrors(): void
    {
        $empty = $this->analyse(seedFreshness: false);
        $this->assertSame('Indisponible', $empty['freshness']['state']);
        $this->assertSame('Indisponible', $empty['metrics']['quotes']);

        $this->account('freshness-evidence');
        $partial = $this->analyse(seedFreshness: false);

        $this->assertSame('Partiel', $partial['freshness']['state']);
        $this->assertTrue($partial['freshness']['stale']);
        $this->assertContains('quotes', $partial['freshness']['affected_modules']);
        $this->assertSame('Indisponible', $partial['metrics']['quotes']);
    }

    public function test_all_failed_modules_report_recent_failure_instead_of_never_synchronized(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'deals', 'tasks', 'events', 'calls', 'notes', 'accounts', 'contacts'] as $module) {
                $this->syncLog($module, 'error');
            }

            $payload = $this->analyse(seedFreshness: false);

            $this->assertSame('Indisponible', $payload['freshness']['state']);
            $this->assertSame('Échec récent', $payload['freshness']['status']);
            $this->assertNotContains(
                'Jamais synchronisé',
                collect($payload['freshness']['modules'])->pluck('status')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_partial_submodule_evidence_reports_incomplete_when_no_module_is_fully_available(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['tasks', 'events', 'calls'] as $module) {
                $this->syncLog($module);
            }

            $payload = $this->analyse(seedFreshness: false);

            $this->assertSame('Indisponible', $payload['freshness']['state']);
            $this->assertSame('Incomplet', $payload['freshness']['status']);
            $this->assertSame(['notes'], $payload['freshness']['modules']['activities']['missing_submodules']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_partial_sync_status_is_incomplete_not_a_recent_error_with_or_without_an_older_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');
        $modules = ['quotes', 'deals', 'tasks', 'events', 'calls', 'notes', 'accounts', 'contacts'];

        try {
            foreach ($modules as $module) {
                $this->syncLog($module, 'partial');
            }
            $withoutSnapshot = $this->analyse(seedFreshness: false);

            $this->assertSame('Indisponible', $withoutSnapshot['freshness']['state']);
            $this->assertSame('Incomplet', $withoutSnapshot['freshness']['status']);
            $this->assertSame('Synchronisation partielle', $withoutSnapshot['freshness']['modules']['quotes']['status']);
            $this->assertSame([], $withoutSnapshot['freshness']['modules']['quotes']['failed_submodules']);
            $this->assertSame(['quotes'], $withoutSnapshot['freshness']['modules']['quotes']['partial_submodules']);

            DB::table('zoho_sync_logs')->delete();
            foreach ($modules as $module) {
                $this->syncLog($module, 'success', '2026-08-10 07:00:00');
                $this->syncLog($module, 'partial', '2026-08-10 08:30:00');
            }
            $withSnapshot = $this->analyse(seedFreshness: false);

            $this->assertSame('Partiel', $withSnapshot['freshness']['state']);
            $this->assertSame('Incomplet', $withSnapshot['freshness']['status']);
            $this->assertSame('Synchronisation partielle', $withSnapshot['freshness']['modules']['quotes']['status']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_missing_activity_submodule_makes_activity_metrics_unavailable(): void
    {
        foreach (['quotes', 'deals', 'tasks', 'events', 'calls', 'accounts', 'contacts'] as $module) {
            $this->syncLog($module);
        }
        $this->activity('activity-without-notes-sync', [
            'activity_type' => 'task',
            'status' => 'Completed',
        ]);

        $payload = $this->analyse(seedFreshness: false);

        $this->assertSame('Partiel', $payload['freshness']['state']);
        $this->assertFalse($payload['freshness']['modules']['activities']['available']);
        $this->assertSame(['notes'], $payload['freshness']['modules']['activities']['missing_submodules']);
        $this->assertSame('Indisponible', $payload['metrics']['tasks_created']);
        $this->assertSame('Indisponible', $payload['metrics']['proven_human_follow_up']);
    }

    public function test_latest_sync_failure_is_not_hidden_by_an_older_success(): void
    {
        $this->seedFreshnessEvidence();
        $this->syncLog('quotes', 'error', now()->addMinute());

        $payload = $this->analyse();

        $this->assertSame('Partiel', $payload['freshness']['state']);
        $this->assertTrue($payload['freshness']['modules']['quotes']['available']);
        $this->assertSame(['quotes'], $payload['freshness']['modules']['quotes']['failed_submodules']);
        $this->assertSame('Échec récent', $payload['freshness']['modules']['quotes']['status']);
        $this->assertContains('quotes', $payload['freshness']['affected_modules']);
        $this->assertSame(0, $payload['metrics']['quotes']);
        $this->assertSame(0, $payload['decision_cards']['P1']['value']);
    }

    public function test_failed_module_without_a_usable_snapshot_remains_unavailable(): void
    {
        foreach (['deals', 'tasks', 'events', 'calls', 'notes', 'accounts', 'contacts'] as $module) {
            $this->syncLog($module);
        }
        $this->syncLog('quotes', 'error');

        $payload = $this->analyse(seedFreshness: false);

        $this->assertSame('Partiel', $payload['freshness']['state']);
        $this->assertFalse($payload['freshness']['modules']['quotes']['available']);
        $this->assertSame(['quotes'], $payload['freshness']['modules']['quotes']['failed_submodules']);
        $this->assertSame('Indisponible', $payload['metrics']['quotes']);
        $this->assertSame('Indisponible', $payload['decision_cards']['P1']['value']);
    }

    public function test_available_p1_rows_remain_actionable_when_p2_sources_are_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'accounts', 'contacts'] as $module) {
                $this->syncLog($module);
            }
            $this->account('partial-p1', ['name' => 'Compte P1 partiel', 'phone' => '0102030405']);
            $this->quote('partial-p1-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'follow_up_status' => null,
                'account_zoho_id' => 'partial-p1',
            ]);

            $payload = $this->analyse(seedFreshness: false);

            $this->assertSame('Partiel', $payload['freshness']['state']);
            $this->assertTrue($payload['queue']['availability_by_priority']['P1']);
            $this->assertFalse($payload['queue']['availability_by_priority']['P2']);
            $this->assertSame(1, $payload['decision_cards']['P1']['value']);
            $this->assertSame('Indisponible', $payload['decision_cards']['P2']['value']);
            $this->assertSame('Compte P1 partiel', $payload['queue']['items'][0]['account']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_contactability_preserves_proven_account_or_contact_channels_when_the_other_source_is_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'accounts'] as $module) {
                $this->syncLog($module);
            }
            $this->account('account-channel', ['name' => 'Compte canal compte', 'phone' => '0102030405']);
            $this->quote('account-channel-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'account-channel',
            ]);

            $accountOnly = $this->analyse(seedFreshness: false);

            $this->assertTrue($accountOnly['queue']['availability_by_priority']['P1']);
            $this->assertSame('partial', $accountOnly['queue']['completeness_by_priority']['P1']);
            $this->assertSame(1, $accountOnly['queue']['total_by_priority']['P1']);

            DB::table('zoho_sync_logs')->delete();
            DB::table('zoho_accounts')->update(['last_synced_at' => null]);
            foreach (['quotes', 'contacts'] as $module) {
                $this->syncLog($module);
            }
            $this->zohoContact('contact-channel', [
                'account_zoho_id' => 'contact-only-account',
                'full_name' => 'Contact canal',
                'phone' => '0612345678',
            ]);
            $this->quote('contact-channel-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'contact-only-account',
                'contact_zoho_id' => 'contact-channel',
            ]);

            $contactOnly = $this->analyse(seedFreshness: false);

            $this->assertTrue($contactOnly['queue']['availability_by_priority']['P1']);
            $this->assertSame('partial', $contactOnly['queue']['completeness_by_priority']['P1']);
            $this->assertSame(1, $contactOnly['queue']['total_by_priority']['P1']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_missing_one_contactability_source_does_not_create_a_false_unreachable_enrichment_row(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'accounts'] as $module) {
                $this->syncLog($module);
            }
            $this->account('unknown-channel', ['name' => 'Compte canal inconnu']);
            $this->quote('unknown-channel-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'unknown-channel',
            ]);

            $payload = $this->analyse(seedFreshness: false);

            $this->assertTrue($payload['queue']['availability_by_priority']['enrichment']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['enrichment']);
            $this->assertSame(0, $payload['queue']['total_by_priority']['enrichment']);
            $this->assertNull(collect($payload['queue']['items'])->firstWhere('account', 'Compte canal inconnu'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_available_p1_does_not_consume_physical_rows_from_unavailable_deal_or_activity_modules(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'accounts'] as $module) {
                $this->syncLog($module);
            }
            $this->owner('owner-a', 'Commercial A');
            $this->owner('owner-b', 'Commercial B');
            $this->account('fresh-p1-account', [
                'name' => 'Compte P1 frais',
                'phone' => '0102030405',
                'owner_zoho_id' => 'owner-a',
            ]);
            $this->quote('fresh-p1-quote', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'account_zoho_id' => 'fresh-p1-account',
                'owner_zoho_id' => 'owner-a',
            ]);

            // These mirror rows deliberately remain present but their modules have no fresh snapshot.
            $this->deal('stale-deal-that-must-not-leak', [
                'account_zoho_id' => 'fresh-p1-account',
                'owner_zoho_id' => 'owner-b',
            ]);
            DB::table('zoho_deals')->where('zoho_id', 'stale-deal-that-must-not-leak')
                ->update(['last_synced_at' => null]);
            $this->activity('stale-call-that-must-not-suppress', [
                'activity_type' => 'call',
                'parent_zoho_id' => 'fresh-p1-quote',
                'owner_zoho_id' => 'owner-b',
            ]);

            $payload = $this->analyse(seedFreshness: false);
            $p1 = collect($payload['queue']['items'])->firstWhere('priority', 'P1');

            $this->assertTrue($payload['queue']['availability_by_priority']['P1']);
            $this->assertSame(1, $payload['queue']['total_by_priority']['P1']);
            $this->assertSame('Commercial A', $p1['owner']);
            $this->assertSame('Indisponible', $p1['last_proven_action']);
            $this->assertStringNotContainsString('propriétaires multiples', $p1['owner']);
            $this->assertFalse($payload['queue']['availability_by_priority']['P2']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_zero_campaign_mappings_make_campaign_priorities_partial_while_no_signals_are_a_complete_known_zero(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->account('campaign-known-zero', ['name' => 'Compte campagne']);
            $withoutSignals = $this->analyse();
            $this->assertSame(0, $withoutSignals['queue']['total_by_priority']['P3']);
            $this->assertSame('complete', $withoutSignals['queue']['completeness_by_priority']['P3']);

            $recipientId = $this->campaignSignal('zero-mapping', [
                'account_zoho_id' => 'campaign-known-zero',
                'full_name' => 'Contact non mappé',
                'email' => 'zero-mapping@example.test',
            ]);
            $localContactId = DB::table('campaign_recipients')->where('id', $recipientId)->value('contact_id');
            DB::table('zoho_contacts')->where('fretiq_contact_id', $localContactId)->delete();

            $payload = $this->analyse();

            $this->assertSame(0, $payload['queue']['total_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['P3']);
            $this->assertSame('partial', $payload['queue']['completeness_by_priority']['enrichment']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_owner_escalation_briefing_uses_quotes_and_activities_even_when_owner_panel_sources_are_unavailable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['quotes', 'tasks', 'events', 'calls', 'notes'] as $module) {
                $this->syncLog($module);
            }
            $this->owner('owner-a', 'Commercial Test');
            $this->quote('briefing-owner-quote', ['quote_date' => '2026-08-09', 'owner_zoho_id' => 'owner-a']);
            $this->activity('briefing-owner-overdue', [
                'activity_type' => 'task',
                'status' => 'Not Started',
                'owner_zoho_id' => 'owner-a',
                'due_at' => '2026-08-09 08:00:00',
            ]);

            $payload = $this->analyse(seedFreshness: false);

            $this->assertSame(1, $payload['briefing']['owner_escalations']);
            $this->assertSame('complete', $payload['briefing']['completeness']['owner_escalations']);
            $this->assertSame('Indisponible', $payload['confidence']['panels']['owners']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_known_p2_subset_remains_numeric_and_enrichment_completeness_includes_p3(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            foreach (['deals', 'tasks', 'events', 'calls', 'notes', 'accounts', 'contacts'] as $module) {
                $this->syncLog($module);
            }
            $this->account('partial-stale-deal', [
                'name' => 'Compte deal partiel',
                'phone' => '0102030405',
            ]);
            $this->deal('partial-stale-deal-record', [
                'account_zoho_id' => 'partial-stale-deal',
                'zoho_created_at' => '2026-01-01 08:00:00',
                'stage_modified_at' => '2026-06-01 08:00:00',
            ]);

            $withoutQuotes = $this->analyse(seedFreshness: false);

            $this->assertFalse($withoutQuotes['freshness']['modules']['quotes']['available']);
            $this->assertTrue($withoutQuotes['queue']['availability_by_priority']['P2']);
            $this->assertSame('partial', $withoutQuotes['queue']['completeness_by_priority']['P2']);
            $this->assertSame(1, $withoutQuotes['queue']['total_by_priority']['P2']);
            $this->assertSame(1, $withoutQuotes['decision_cards']['P2']['value']);
            $this->assertSame('partial', $withoutQuotes['briefing']['completeness']['reachable_accounts']);
            $this->assertIsInt($withoutQuotes['briefing']['reachable_accounts']);
            $this->assertSame(
                'Compte deal partiel',
                collect($withoutQuotes['queue']['items'])->firstWhere('priority', 'P2')['account'],
            );

            $this->syncLog('quotes');
            $completeKnownZeroCampaign = $this->analyse(seedFreshness: false);

            $this->assertTrue($completeKnownZeroCampaign['queue']['availability_by_priority']['enrichment']);
            $this->assertSame(
                'complete',
                $completeKnownZeroCampaign['queue']['completeness_by_priority']['enrichment'],
            );
            $this->assertIsInt($completeKnownZeroCampaign['queue']['total_by_priority']['enrichment']);
            $this->assertTrue($completeKnownZeroCampaign['queue']['availability_by_priority']['P3']);
            $this->assertSame(0, $completeKnownZeroCampaign['decision_cards']['P3']['value']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_campaign_signal_is_preserved_when_the_account_has_a_higher_priority(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->owner('owner-a', 'Commercial A');
            $this->owner('owner-b', 'Commercial B');
            $this->owner('owner-z', 'Commercial Z');
            $this->account('overlap-account', ['owner_zoho_id' => null, 'name' => 'Compte signal croisé', 'phone' => '0102030405']);
            $this->campaignSignal('overlap', [
                'owner_zoho_id' => 'owner-a',
                'account_zoho_id' => 'overlap-account',
                'full_name' => 'Contact signal croisé',
                'email' => 'overlap@example.test',
            ]);
            $this->quote('overlap-quote', [
                'owner_zoho_id' => 'owner-z',
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'follow_up_status' => null,
                'account_zoho_id' => 'overlap-account',
                'contact_zoho_id' => 'zoho-overlap',
            ]);

            $row = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte signal croisé');

            $this->assertSame('P1', $row['priority']);
            $this->assertContains('P1', $row['signals']);
            $this->assertContains('P3', $row['signals']);
            $this->assertSame('Commercial A · propriétaires multiples', $row['owner']);

            $this->account('overlap-missing-owner', ['owner_zoho_id' => null, 'name' => 'Compte propriétaire de repli', 'phone' => '0102030405']);
            $this->campaignSignal('overlap-missing-owner', [
                'owner_zoho_id' => 'owner-b',
                'account_zoho_id' => 'overlap-missing-owner',
                'full_name' => 'Contact propriétaire de repli',
                'email' => 'overlap-missing-owner@example.test',
            ]);
            $this->quote('overlap-missing-owner-quote', [
                'owner_zoho_id' => null,
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-11',
                'follow_up_status' => null,
                'account_zoho_id' => 'overlap-missing-owner',
                'contact_zoho_id' => 'zoho-overlap-missing-owner',
            ]);

            $fallbackRow = collect($this->analyse()['queue']['items'])->firstWhere('account', 'Compte propriétaire de repli');
            $this->assertSame('Commercial B', $fallbackRow['owner']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_future_quotes_are_excluded_and_volume_mix_is_readable(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->quote('current', [
                'quote_date' => '2026-08-09',
                'transport_type' => json_encode(['Aérien Cargo'], JSON_THROW_ON_ERROR),
                'origin' => 'Paris',
                'destination' => 'Casablanca',
            ]);
            $this->quote('future', [
                'quote_date' => '2026-08-12',
                'transport_type' => json_encode(['Maritime'], JSON_THROW_ON_ERROR),
                'origin' => 'Le Havre',
                'destination' => 'Tanger',
            ]);

            $payload = $this->analyse();

            $this->assertSame(1, $payload['metrics']['quotes']);
            $this->assertSame(1, $payload['quote_risks']['future_date_anomalies']);
            $this->assertSame(1, $payload['transport_mix']['Aérien Cargo']);
            $this->assertArrayNotHasKey('["Aérien Cargo"]', $payload['transport_mix']);
            $this->assertSame(1, $payload['lane_mix']['Paris → Casablanca']);
            $this->assertArrayNotHasKey('Le Havre → Tanger', $payload['lane_mix']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_owner_evidence_includes_all_time_overdue_and_exact_activity_columns(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->owner('owner-a', 'Commercial Test');
            $this->quote('owner-quote', [
                'quote_date' => '2026-08-09',
                'owner_zoho_id' => 'owner-a',
                'contact_zoho_id' => 'owner-contact',
                'deal_zoho_id' => 'owner-deal',
            ]);
            $this->zohoContact('owner-contact', ['owner_zoho_id' => 'owner-a', 'full_name' => 'Masqué']);
            $this->zohoContact('owner-deal-contact', ['owner_zoho_id' => 'owner-a', 'full_name' => 'Masqué Deal']);
            $this->deal('owner-only-deal', [
                'owner_zoho_id' => 'owner-a',
                'contact_zoho_id' => 'owner-deal-contact',
            ]);
            $this->activity('old-overdue', [
                'owner_zoho_id' => 'owner-a',
                'activity_type' => 'task',
                'status' => 'Not Started',
                'zoho_created_at' => '2025-01-01 09:00:00',
                'activity_at' => '2025-01-01 09:00:00',
                'due_at' => '2025-01-02 09:00:00',
            ]);
            $this->activity('done', ['owner_zoho_id' => 'owner-a', 'activity_type' => 'task', 'status' => 'Completed']);
            $this->activity('meeting', ['owner_zoho_id' => 'owner-a', 'activity_type' => 'meeting']);
            $this->activity('call', ['owner_zoho_id' => 'owner-a', 'activity_type' => 'call']);
            $this->activity('note', ['owner_zoho_id' => 'owner-a', 'activity_type' => 'note']);

            $owner = collect($this->analyse()['owners'])->firstWhere('owner', 'Commercial Test');

            $this->assertSame([
                'owner', 'quotes', 'missing_status', 'overdue_tasks', 'completed_tasks',
                'meetings', 'calls', 'notes', 'linked_contacts', 'linked_deals',
            ], array_keys($owner));
            $this->assertSame(1, $owner['overdue_tasks']);
            $this->assertSame(1, $owner['completed_tasks']);
            $this->assertSame(1, $owner['meetings']);
            $this->assertSame(1, $owner['calls']);
            $this->assertSame(1, $owner['notes']);
            $this->assertSame(2, $owner['linked_contacts']);
            $this->assertSame(2, $owner['linked_deals']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_monthly_evidence_has_all_exact_series_from_batched_source_rows(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $this->quote('july-quote', [
                'quote_date' => '2026-07-15',
                'follow_up_status' => 'Affaire gagnée',
            ]);
            $this->deal('july-deal', ['zoho_created_at' => '2026-07-15 08:00:00']);
            $this->activity('july-task', [
                'activity_type' => 'task',
                'status' => 'Completed',
                'zoho_created_at' => '2026-07-15 08:00:00',
                'activity_at' => '2026-07-16 08:00:00',
            ]);
            $this->activity('july-note', [
                'activity_type' => 'note',
                'zoho_created_at' => '2026-07-17 08:00:00',
                'activity_at' => '2026-07-17 08:00:00',
            ]);

            $months = collect($this->analyse()['monthly']);
            $july = $months->firstWhere('month', '2026-07');

            $this->assertCount(6, $months);
            $this->assertSame([
                'month', 'quotes', 'current_decisions', 'tasks_created', 'completed_tasks',
                'meetings', 'calls', 'notes', 'deals',
            ], array_keys($july));
            $this->assertSame(1, $july['quotes']);
            $this->assertSame(1, $july['current_decisions']);
            $this->assertSame(1, $july['tasks_created']);
            $this->assertSame(1, $july['completed_tasks']);
            $this->assertSame(1, $july['notes']);
            $this->assertSame(1, $july['deals']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ceo_control_tower_keeps_all_priority_classes_when_queue_is_truncated(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 09:00:00 Europe/Paris');

        try {
            $accounts = [];
            foreach (range(1, 55) as $i) {
                $accountId = 'urgent-'.$i;
                $accounts[] = array_merge($this->mirrorBase(), [
                    'zoho_id' => $accountId,
                    'owner_zoho_id' => 'owner-a',
                    'name' => 'Urgent '.$i,
                    'phone' => '0100000000',
                ]);
                $this->quote('urgent-quote-'.$i, [
                    'quote_date' => '2026-08-09',
                    'valid_till' => '2026-08-10',
                    'account_zoho_id' => $accountId,
                ]);
            }
            DB::table('zoho_accounts')->insert($accounts);
            $this->account('repeat', ['phone' => '0100000000']);
            $this->quote('repeat-a', ['quote_date' => '2026-08-09', 'valid_till' => '2026-09-10', 'account_zoho_id' => 'repeat']);
            $this->quote('repeat-b', ['quote_date' => '2026-08-09', 'valid_till' => '2026-09-11', 'account_zoho_id' => 'repeat']);
            $this->account('no-channel', ['name' => 'Sans canal']);
            $this->quote('no-channel', [
                'quote_date' => '2026-08-09',
                'valid_till' => '2026-08-10',
                'account_zoho_id' => 'no-channel',
            ]);
            $this->account('account-p3', ['name' => 'Compte P3']);
            $this->campaignSignal('diversity', [
                'account_zoho_id' => 'account-p3',
                'full_name' => 'P3 Contact',
                'email' => 'p3-diversity@example.test',
                'opened_at' => '2026-08-09 10:00:00',
            ]);

            $payload = $this->analyse();

            $this->assertTrue($payload['queue']['truncated']);
            $this->assertLessThanOrEqual(50, count($payload['queue']['items']));
            foreach (['P1', 'P2', 'P3', 'enrichment'] as $priority) {
                $this->assertGreaterThan(0, $payload['queue']['displayed_by_priority'][$priority]);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_filter_scope_discloses_quote_only_and_partially_applied_filters(): void
    {
        $payload = $this->analyse(filters: [
            'country' => 'France',
            'transport' => 'Aérien Cargo',
            'currency' => 'EUR',
        ]);

        $scope = $payload['meta']['filter_scope'];

        $this->assertEqualsCanonicalizing(['country', 'transport', 'currency'], $scope['requested']);
        $this->assertSame('Filtré', $scope['metrics']['quotes']);
        $this->assertSame('Filtré', $scope['metrics']['missing_status']);
        foreach (['tasks_created', 'completed_tasks', 'meetings', 'calls', 'notes', 'proven_human_follow_up'] as $metric) {
            $this->assertSame('Non filtré', $scope['metrics'][$metric]);
        }
        $this->assertSame('Filtré', $scope['panels']['quote_risks']);
        $this->assertSame('Filtré', $scope['panels']['mix']);
        $this->assertSame('Partiel', $scope['panels']['monthly']);
        $this->assertSame('Partiel', $scope['panels']['owners']);
        $this->assertSame('Partiel', $scope['briefing']);
        $this->assertSame('Partiel', $scope['queue']);
    }

    public function test_unavailable_payload_keeps_the_complete_stable_shape(): void
    {
        $payload = $this->analyse(scope: new MarketingScope(null, [], false, true));

        $this->assertSame('Indisponible', $payload['metrics']['quotes']);
        $this->assertSame([], $payload['queue']['items']);
        $this->assertSame(['P1', 'P2', 'P3', 'enrichment'], array_keys($payload['decision_cards']));
        $this->assertSame(['P1', 'P2', 'P3', 'enrichment'], array_keys($payload['queue']['total_by_priority']));
        $this->assertSame([
            'expired_missing_decision', 'due_7_days', 'due_30_days', 'unreachable', 'future_date_anomalies',
        ], array_keys($payload['quote_risks']));
        $this->assertArrayHasKey('briefing', $payload);
        $this->assertArrayHasKey('lane_mix', $payload);
        $this->assertSame('Indisponible', $payload['decision_cards']['P3']['value']);
        $this->assertSame([
            'P1' => false, 'P2' => false, 'P3' => false, 'enrichment' => false,
        ], $payload['queue']['availability_by_priority']);
        $this->assertSame('Indisponible', $payload['queue']['total_by_priority']['P1']);
        $this->assertSame('unavailable', $payload['briefing']['completeness']['reachable_accounts']);
        $this->assertArrayHasKey('monthly_meta', $payload);
        $this->assertSame('Indisponible', $payload['confidence']['panels']['monthly']);
        $this->assertArrayHasKey('task_completion_basis', $payload['meta']);
    }

    /** @param array<string,mixed> $filters */
    private function analyse(
        ?MarketingPeriod $period = null,
        ?MarketingScope $scope = null,
        array $filters = [],
        bool $seedFreshness = true,
    ): array {
        $period ??= MarketingPeriod::fromInput(['preset' => '30d']);
        $scope ??= new MarketingScope(null, [], true, false);
        if ($seedFreshness) {
            $this->seedFreshnessEvidence();
        }

        return app(ZohoCeoControlTower::class)->analyse(
            new MarketingAnalyticsQuery(null, $period, $filters),
            $scope,
        );
    }

    private function seedFreshnessEvidence(): void
    {
        foreach (['quotes', 'deals', 'tasks', 'events', 'calls', 'notes', 'accounts', 'contacts'] as $module) {
            if (DB::table('zoho_sync_logs')->where('module', $module)->exists()) {
                continue;
            }
            DB::table('zoho_sync_logs')->insert([
                'module' => $module,
                'mode' => 'delta',
                'status' => 'success',
                'synced_at' => now(),
                'records_synced' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function syncLog(string $module, string $status = 'success', mixed $syncedAt = null): void
    {
        DB::table('zoho_sync_logs')->insert([
            'module' => $module,
            'mode' => 'delta',
            'status' => $status,
            'synced_at' => $syncedAt ?? now(),
            'records_synced' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function quote(string $id, array $extra = []): void
    {
        DB::table('zoho_quotes')->insert(array_merge($this->mirrorBase(), [
            'zoho_id' => $id,
            'owner_zoho_id' => 'owner-a',
        ], $extra));
    }

    /** @param array<string,mixed> $extra */
    private function activity(string $id, array $extra = []): void
    {
        DB::table('zoho_activities')->insert(array_merge($this->mirrorBase(), [
            'zoho_id' => $id,
            'owner_zoho_id' => 'owner-a',
            'zoho_created_at' => '2026-08-09 09:00:00',
            'activity_at' => '2026-08-09 09:00:00',
        ], $extra));
    }

    /** @param array<string,mixed> $extra */
    private function deal(string $id, array $extra = []): void
    {
        DB::table('zoho_deals')->insert(array_merge($this->mirrorBase(), [
            'zoho_id' => $id,
            'owner_zoho_id' => 'owner-a',
            'stage' => 'Qualification',
        ], $extra));
    }

    /** @param array<string,mixed> $extra */
    private function account(string $id, array $extra = []): void
    {
        DB::table('zoho_accounts')->insert(array_merge($this->mirrorBase(), [
            'zoho_id' => $id,
            'owner_zoho_id' => 'owner-a',
            'name' => 'Compte '.$id,
        ], $extra));
    }

    /** @param array<string,mixed> $extra */
    private function zohoContact(string $id, array $extra = []): void
    {
        DB::table('zoho_contacts')->insert(array_merge($this->mirrorBase(), [
            'zoho_id' => $id,
            'owner_zoho_id' => 'owner-a',
        ], $extra));
    }

    private function owner(string $id, string $name): void
    {
        DB::table('zoho_users')->insert(array_merge($this->mirrorBase(), [
            'zoho_id' => $id,
            'full_name' => $name,
        ]));
    }

    /** @param array<string,mixed> $contact */
    private function campaignSignal(string $suffix, array $contact): int
    {
        $local = Contact::factory()->create([
            'email' => 'local-'.$suffix.'@example.test',
            'assigned_to' => $contact['assigned_to'] ?? null,
        ]);
        $this->zohoContact('zoho-'.$suffix, array_merge([
            'fretiq_contact_id' => $local->id,
        ], collect($contact)->except(['opened_at', 'assigned_to'])->all()));

        $now = now();
        $segmentId = DB::table('segments')->insertGetId([
            'name' => 'Segment '.$suffix,
            'scope' => 'client',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $templateId = DB::table('campaign_templates')->insertGetId([
            'name' => 'Template '.$suffix,
            'subject' => 'Sujet',
            'html_content' => '<p>Contenu</p>',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $senderId = DB::table('sender_identities')->insertGetId([
            'name' => 'Sender '.$suffix,
            'email' => 'sender-'.$suffix.'@example.test',
            'is_default' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $campaignId = DB::table('campaigns')->insertGetId([
            'segment_id' => $segmentId,
            'template_id' => $templateId,
            'sender_identity_id' => $senderId,
            'name' => 'Campaign '.$suffix,
            'schedule_type' => 'one_shot',
            'timezone' => 'Europe/Paris',
            'driver' => 'local',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runId = DB::table('campaign_runs')->insertGetId([
            'campaign_id' => $campaignId,
            'occurrence_key' => 'occurrence-'.$suffix,
            'run_at' => '2026-08-09 08:00:00',
            'status' => 'sent',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('campaign_recipients')->insertGetId([
            'campaign_run_id' => $runId,
            'contact_id' => $local->id,
            'status' => 'opened',
            'sent_at' => '2026-08-09 09:00:00',
            'opened_at' => $contact['opened_at'] ?? '2026-08-09 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string,mixed> */
    private function mirrorBase(): array
    {
        return [
            'raw_payload' => '{}',
            'payload_hash' => str_repeat('a', 64),
            'last_seen_at' => now(),
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
