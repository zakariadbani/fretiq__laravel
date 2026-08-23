<?php

namespace Tests\Unit\Services\Campaign;

use App\Models\CampaignTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Services\Campaign\SequenceCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SequenceCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    private function makeTemplate(string $name): CampaignTemplate
    {
        return CampaignTemplate::create([
            'name' => $name,
            'subject' => 'Sujet',
            'html_content' => '<p>Corps</p>',
        ]);
    }

    private function makeContact(string $email): Contact
    {
        $company = Company::create([
            'name' => 'Acme',
            'relationship' => 'client',
            'source' => 'manual',
            'qualification_status' => 'pending',
        ]);

        return Contact::create([
            'company_id' => $company->id,
            'email' => $email,
            'name' => 'J',
            'source' => 'manual',
            'email_kind' => 'role',
            'email_verification_status' => 'valid',
            'email_verification_source' => 'hunter',
            'email_verification_checked_at' => now(),
        ]);
    }

    private function csv(string $rows): string
    {
        return "name;step_no;delay_days;template_name;subject\n{$rows}\n";
    }

    public function test_it_groups_steps_by_sequence_creates_inactive_and_replaces_steps_on_reimport(): void
    {
        $this->makeTemplate('Relance J1');
        $this->makeTemplate('Relance J3');
        $importer = app(SequenceCsvImporter::class);

        $rows = $importer->parse($this->csv(
            "Onboarding;1;0;Relance J1;Bienvenue\nOnboarding;2;3;Relance J3;Vous avez manqué quelque chose ?"
        ), 'sequences.csv');

        $groups = $importer->groupIntoSequences($rows);
        $this->assertCount(1, $groups);
        $this->assertSame('Onboarding', $groups[0]['name']);
        $this->assertFalse($groups[0]['will_update']);
        $this->assertCount(2, $groups[0]['steps']);
        $this->assertSame([1, 2], array_column($groups[0]['steps'], 'step_no'));

        $result = $importer->store($rows);
        $this->assertSame(['created' => 1, 'updated' => 0, 'steps' => 2, 'warnings' => []], $result);

        $sequence = Sequence::where('name', 'Onboarding')->firstOrFail();
        $this->assertFalse((bool) $sequence->is_active);
        $this->assertCount(2, $sequence->steps);

        // Activate manually (mirrors a real admin flipping it on after import),
        // then re-import with a single, different step — steps must be REPLACED,
        // not appended, and the existing active state must be preserved. No
        // enrollment/run references this sequence, so the dropped step_no=2
        // is a safe delete.
        $sequence->update(['is_active' => true]);

        $reimportRows = $importer->parse($this->csv('Onboarding;1;7;Relance J1;Nouveau message unique'), 'sequences.csv');
        $result = $importer->store($reimportRows);
        $this->assertSame(['created' => 0, 'updated' => 1, 'steps' => 1, 'warnings' => []], $result);

        $sequence->refresh();
        $this->assertTrue((bool) $sequence->is_active, 'is_active must be preserved on update, not reset.');
        $this->assertCount(1, $sequence->steps()->get());
        $this->assertSame(7, $sequence->steps()->first()->delay_days);
    }

    public function test_it_preserves_surplus_steps_and_warns_when_sequence_has_an_active_enrollment(): void
    {
        $this->makeTemplate('Relance J1');
        $this->makeTemplate('Relance J3');
        $importer = app(SequenceCsvImporter::class);

        $rows = $importer->parse($this->csv(
            "Onboarding;1;0;Relance J1;Bienvenue\nOnboarding;2;3;Relance J3;Vous avez manqué quelque chose ?"
        ), 'sequences.csv');
        $importer->store($rows);

        $sequence = Sequence::where('name', 'Onboarding')->firstOrFail();
        $stepOne = $sequence->steps()->where('step_no', 1)->firstOrFail();
        $stepTwo = $sequence->steps()->where('step_no', 2)->firstOrFail();

        SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $this->makeContact('active@acme.test')->id,
            'current_step' => 0,
            'status' => 'active',
            'next_send_at' => now(),
        ]);

        // Re-import drops step_no=2. Deleting it would strand any
        // campaign_runs.sequence_step_id row still pointing at it and let
        // SequenceWaveService re-adopt the enrollment into a duplicate run
        // — so with a live active enrollment, the surplus step must be kept
        // and a warning returned instead of a silent delete.
        $reimportRows = $importer->parse($this->csv('Onboarding;1;7;Relance J1;Nouveau message unique'), 'sequences.csv');
        $result = $importer->store($reimportRows);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['steps']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('Onboarding', $result['warnings'][0]);
        $this->assertStringContainsString('conservées', $result['warnings'][0]);

        $sequence->refresh();
        $this->assertCount(2, $sequence->steps()->get(), 'surplus step_no=2 must be preserved, not deleted.');

        $stepOne->refresh();
        $this->assertSame($stepOne->id, $sequence->steps()->where('step_no', 1)->firstOrFail()->id, 'step_no=1 must be updated in place, not delete+recreate.');
        $this->assertSame(7, $stepOne->delay_days, 'the retained step must still receive the new field values.');
        $this->assertNotNull(SequenceStep::find($stepTwo->id), 'the surplus row itself must still exist under its original id.');
    }

    public function test_it_rejects_a_step_referencing_an_unknown_template(): void
    {
        $importer = app(SequenceCsvImporter::class);

        try {
            $importer->parse($this->csv('Onboarding;1;0;Modele Inconnu;Bienvenue'), 'sequences.csv');
            $this->fail('Expected a validation exception for a missing template.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv', $exception->errors());
            $this->assertStringContainsString('introuvable', $exception->errors()['csv'][0]);
        }

        $this->assertDatabaseCount('sequences', 0);
    }
}
