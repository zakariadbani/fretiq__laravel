<?php

namespace Tests\Unit\Services\Zoho\V2\Identity;

use App\Models\Contact;
use App\Models\User;
use App\Models\Zoho\ZohoContact;
use App\Models\Zoho\ZohoLead;
use App\Models\Zoho\ZohoMarketingLink;
use App\Models\Zoho\ZohoUser;
use App\Models\Zoho\ZohoUserMapping;
use App\Services\Zoho\V2\Identity\ZohoIdentityLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoIdentityLinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_auto_maps_only_a_unique_normalized_email_and_rejects_ambiguity(): void
    {
        User::factory()->create(['email' => 'Commercial@Example.test', 'is_active' => true]);
        $this->zohoUser('zoho-unique', ' commercial@example.test ');
        $this->zohoUser('zoho-ambiguous-a', 'shared@example.test');
        $this->zohoUser('zoho-ambiguous-b', 'SHARED@example.test');
        User::factory()->create(['email' => 'shared@example.test', 'is_active' => true]);

        $result = app(ZohoIdentityLinker::class)->autoMapUsers();

        $mapping = ZohoUserMapping::query()->where('zoho_user_id', 'zoho-unique')->sole();
        $this->assertSame(['mapped' => 1, 'ambiguous' => 2], $result);
        $this->assertSame('exact_email', $mapping->match_method);
        $this->assertTrue($mapping->is_confirmed);
        $this->assertNull(ZohoUserMapping::query()->where('zoho_user_id', 'zoho-ambiguous-a')->first());
    }

    public function test_admin_override_is_audited_and_no_name_matching_is_possible(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['email' => 'different@example.test']);
        $this->zohoUser('zoho-name-only', 'zoho@example.test', 'Same Display Name');

        $mapping = app(ZohoIdentityLinker::class)->overrideUserMapping('zoho-name-only', $target->id, $actor->id);
        $this->assertSame('admin_override', $mapping->match_method);
        $this->assertTrue($mapping->is_override);
        $this->assertSame($actor->id, $mapping->confirmed_by_user_id);
        $this->assertSame('admin_override', $mapping->audit_metadata['event']);

        $this->assertSame(['mapped' => 0, 'ambiguous' => 1], app(ZohoIdentityLinker::class)->autoMapUsers());
        $this->assertSame($target->id, $mapping->fresh()->fretiq_user_id);
    }

    public function test_auto_mapping_never_replaces_a_confirmed_admin_override(): void
    {
        $actor = User::factory()->create();
        $emailMatched = User::factory()->create(['email' => 'mapped@example.test', 'is_active' => true]);
        $overrideTarget = User::factory()->create(['email' => 'override@example.test', 'is_active' => true]);
        $this->zohoUser('zoho-protected', 'mapped@example.test');

        app(ZohoIdentityLinker::class)->overrideUserMapping('zoho-protected', $overrideTarget->id, $actor->id);
        $result = app(ZohoIdentityLinker::class)->autoMapUsers();

        $this->assertSame(['mapped' => 0, 'ambiguous' => 0], $result);
        $this->assertSame($overrideTarget->id, ZohoUserMapping::query()->where('zoho_user_id', 'zoho-protected')->value('fretiq_user_id'));
        $this->assertNotSame($emailMatched->id, ZohoUserMapping::query()->where('zoho_user_id', 'zoho-protected')->value('fretiq_user_id'));
    }

    public function test_auto_mappings_are_invalidated_when_exact_identity_evidence_stops_being_unique_and_current(): void
    {
        $emailChangedUser = User::factory()->create(['email' => 'changed@example.test', 'is_active' => true]);
        $duplicateUser = User::factory()->create(['email' => 'duplicate@example.test', 'is_active' => true]);
        $tombstonedUser = User::factory()->create(['email' => 'deleted@example.test', 'is_active' => true]);
        $inactiveUser = User::factory()->create(['email' => 'inactive@example.test', 'is_active' => true]);
        $emailChangedZoho = $this->zohoUser('zoho-changed', 'changed@example.test');
        $this->zohoUser('zoho-duplicate', 'duplicate@example.test');
        $tombstonedZoho = $this->zohoUser('zoho-deleted', 'deleted@example.test');
        $this->zohoUser('zoho-inactive', 'inactive@example.test');

        $this->assertSame(4, app(ZohoIdentityLinker::class)->autoMapUsers()['mapped']);

        $emailChangedZoho->update(['email' => 'new@example.test', 'normalized_email' => 'new@example.test']);
        $this->zohoUser('zoho-duplicate-second', 'duplicate@example.test');
        $tombstonedZoho->update(['zoho_deleted_at' => now(), 'zoho_deletion_type' => 'recycle']);
        $inactiveUser->update(['is_active' => false]);

        app(ZohoIdentityLinker::class)->autoMapUsers();

        foreach (['zoho-changed', 'zoho-duplicate', 'zoho-deleted', 'zoho-inactive'] as $zohoId) {
            $mapping = ZohoUserMapping::query()->where('zoho_user_id', $zohoId)->sole();
            $this->assertFalse($mapping->is_confirmed, $zohoId);
            $this->assertNull($mapping->fretiq_user_id, $zohoId);
            $this->assertSame('exact_email_auto_match_invalidated', $mapping->audit_metadata['event'], $zohoId);
        }

        $this->assertNotNull($emailChangedUser->id);
        $this->assertNotNull($duplicateUser->id);
        $this->assertNotNull($tombstonedUser->id);
    }

    public function test_marketing_links_are_unique_exact_email_only_and_explicitly_non_causal(): void
    {
        $contact = Contact::factory()->create(['email' => 'touch@example.test']);
        $this->zohoLead('lead-touch', ' TOUCH@example.test ');
        $this->zohoContact('contact-no-match', 'no-match@example.test');

        $this->assertSame(1, ZohoLead::query()->current()->count());
        $this->assertSame('touch@example.test', strtolower(trim((string) $contact->email)));

        $result = app(ZohoIdentityLinker::class)->linkMarketingContacts();
        $link = ZohoMarketingLink::query()->sole();

        $this->assertSame(['created' => 1, 'ambiguous' => 1], $result);
        $this->assertSame('leads', $link->zoho_module);
        $this->assertSame($contact->id, $link->fretiq_entity_id);
        $this->assertSame('exact_email', $link->audit_metadata['match_method']);
        $this->assertSame('non_causal', $link->audit_metadata['attribution']);
        $this->assertStringStartsWith('v1:', $link->match_key_hash);
        $this->assertNotSame(hash('sha256', 'touch@example.test'), $link->match_key_hash);
        $firstHash = $link->match_key_hash;
        $this->assertSame(0, app(ZohoIdentityLinker::class)->linkMarketingContacts()['created']);
        $this->assertSame(1, ZohoMarketingLink::query()->count());
        $this->assertSame($firstHash, $link->fresh()->match_key_hash);
    }

    public function test_marketing_linking_does_not_require_the_users_api_mirror(): void
    {
        Contact::factory()->create(['email' => 'no-users-api@example.test']);
        $this->zohoContact('contact-with-owner-only', 'no-users-api@example.test');

        $result = app(ZohoIdentityLinker::class)->linkMarketingContacts();

        $this->assertSame(['created' => 1, 'ambiguous' => 0], $result);
        $this->assertSame(0, ZohoUser::query()->count());
        $this->assertSame('non_causal', ZohoMarketingLink::query()->sole()->audit_metadata['attribution']);
    }

    public function test_marketing_links_are_tombstoned_when_either_side_email_changes(): void
    {
        $fretiqChanged = Contact::factory()->create(['email' => 'fretiq-change@example.test']);
        $zohoChangedContact = Contact::factory()->create(['email' => 'zoho-change@example.test']);
        $this->zohoLead('lead-fretiq-change', 'fretiq-change@example.test');
        $zohoChanged = $this->zohoContact('contact-zoho-change', 'zoho-change@example.test');

        $this->assertSame(2, app(ZohoIdentityLinker::class)->linkMarketingContacts()['created']);

        $fretiqChanged->update(['email' => 'fretiq-changed-away@example.test']);
        $zohoChanged->update(['email' => 'zoho-changed-away@example.test', 'normalized_email' => 'zoho-changed-away@example.test']);
        app(ZohoIdentityLinker::class)->linkMarketingContacts();

        foreach (['lead-fretiq-change', 'contact-zoho-change'] as $zohoId) {
            $link = ZohoMarketingLink::query()->where('zoho_record_id', $zohoId)->sole();
            $this->assertFalse($link->is_active, $zohoId);
            $this->assertSame('exact_email_changed', $link->invalidation_reason, $zohoId);
            $this->assertSame(1, $link->invalidation_count, $zohoId);
            $this->assertNotNull($link->invalidated_at, $zohoId);
            $this->assertSame('exact_email_link_invalidated', $link->audit_metadata['event'], $zohoId);
            $this->assertStringNotContainsString('@example.test', json_encode($link->audit_metadata, JSON_THROW_ON_ERROR), $zohoId);
        }

        $this->assertSame(2, ZohoMarketingLink::query()->count());
        $this->assertNotNull($zohoChangedContact->id);
    }

    public function test_marketing_links_are_tombstoned_for_ambiguity_and_deleted_records(): void
    {
        Contact::factory()->create(['email' => 'ambiguous-link@example.test']);
        $deletedFretiq = Contact::factory()->create(['email' => 'deleted-fretiq@example.test']);
        Contact::factory()->create(['email' => 'deleted-zoho@example.test']);
        $this->zohoLead('lead-ambiguous', 'ambiguous-link@example.test');
        $this->zohoLead('lead-deleted-fretiq', 'deleted-fretiq@example.test');
        $deletedZoho = $this->zohoContact('contact-deleted-zoho', 'deleted-zoho@example.test');

        $this->assertSame(3, app(ZohoIdentityLinker::class)->linkMarketingContacts()['created']);

        $this->zohoLead('lead-ambiguous-second', 'ambiguous-link@example.test');
        $deletedFretiq->delete();
        $deletedZoho->update(['zoho_deleted_at' => now(), 'zoho_deletion_type' => 'recycle']);
        app(ZohoIdentityLinker::class)->linkMarketingContacts();

        $this->assertSame('zoho_email_not_unique', ZohoMarketingLink::query()->where('zoho_record_id', 'lead-ambiguous')->sole()->invalidation_reason);
        $this->assertSame('fretiq_contact_not_current', ZohoMarketingLink::query()->where('zoho_record_id', 'lead-deleted-fretiq')->sole()->invalidation_reason);
        $this->assertSame('zoho_record_not_current', ZohoMarketingLink::query()->where('zoho_record_id', 'contact-deleted-zoho')->sole()->invalidation_reason);
        $this->assertSame(0, ZohoMarketingLink::query()->where('is_active', true)->count());
        $this->assertSame(3, ZohoMarketingLink::query()->count());
    }

    public function test_invalidated_marketing_link_revalidates_the_same_row_and_remains_idempotent(): void
    {
        $contact = Contact::factory()->create(['email' => 'revalidate@example.test']);
        $zoho = $this->zohoLead('lead-revalidate', 'revalidate@example.test');
        app(ZohoIdentityLinker::class)->linkMarketingContacts();
        $original = ZohoMarketingLink::query()->sole();

        $zoho->update(['email' => 'temporarily-changed@example.test', 'normalized_email' => 'temporarily-changed@example.test']);
        app(ZohoIdentityLinker::class)->linkMarketingContacts();
        $this->assertFalse($original->fresh()->is_active);

        $zoho->update(['email' => 'revalidate@example.test', 'normalized_email' => 'revalidate@example.test']);
        $this->assertSame(0, app(ZohoIdentityLinker::class)->linkMarketingContacts()['created']);
        $revalidated = $original->fresh();
        $this->assertSame($original->id, $revalidated->id);
        $this->assertTrue($revalidated->is_active);
        $this->assertSame(2, $revalidated->validation_count);
        $this->assertSame(1, $revalidated->invalidation_count);
        $this->assertNotNull($revalidated->invalidated_at);
        $this->assertSame('exact_email_changed', $revalidated->invalidation_reason);
        $this->assertSame('exact_email_link_revalidated', $revalidated->audit_metadata['event']);
        $this->assertStringStartsWith('v1:', $revalidated->match_key_hash);

        $unchangedColumns = ['id', 'match_key_hash', 'matched_at', 'validated_at', 'invalidated_at', 'invalidation_reason', 'validation_count', 'invalidation_count', 'updated_at'];
        $unchanged = array_intersect_key($revalidated->getRawOriginal(), array_flip($unchangedColumns));
        $this->assertSame(0, app(ZohoIdentityLinker::class)->linkMarketingContacts()['created']);
        $this->assertSame($unchanged, array_intersect_key($revalidated->fresh()->getRawOriginal(), array_flip($unchangedColumns)));
        $this->assertSame(1, ZohoMarketingLink::query()->count());
        $this->assertSame($contact->id, $revalidated->fretiq_entity_id);
    }

    private function zohoUser(string $id, string $email, string $name = 'Name'): ZohoUser
    {
        return ZohoUser::query()->create(['zoho_id' => $id, 'normalized_email' => strtolower(trim($email)), 'email' => trim($email), 'full_name' => $name, 'raw_payload' => [], 'payload_hash' => hash('sha256', $id)]);
    }

    private function zohoLead(string $id, string $email): ZohoLead
    {
        return ZohoLead::query()->create(['zoho_id' => $id, 'normalized_email' => strtolower(trim($email)), 'email' => trim($email), 'raw_payload' => [], 'payload_hash' => hash('sha256', $id)]);
    }

    private function zohoContact(string $id, string $email): ZohoContact
    {
        return ZohoContact::query()->create(['zoho_id' => $id, 'normalized_email' => strtolower(trim($email)), 'email' => trim($email), 'raw_payload' => [], 'payload_hash' => hash('sha256', $id)]);
    }
}
