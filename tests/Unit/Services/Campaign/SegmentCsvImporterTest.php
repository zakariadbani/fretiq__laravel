<?php

namespace Tests\Unit\Services\Campaign;

use App\Models\Segment;
use App\Services\Campaign\SegmentCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SegmentCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $row): string
    {
        return "name;scope;filter_json;notes\n{$row}\n";
    }

    public function test_it_parses_and_stores_upserting_by_case_insensitive_name(): void
    {
        $importer = app(SegmentCsvImporter::class);

        $rows = $importer->parse($this->csv('Clients Transport;client;{"sector":["Transport"]};Segment de test'), 'segments.csv');

        $this->assertSame('client', $rows[0]['scope']);
        $this->assertSame(['sector' => ['Transport']], $rows[0]['filter']);
        $this->assertFalse($rows[0]['will_update']);

        $result = $importer->store($rows);
        $this->assertSame(['created' => 1, 'updated' => 0, 'warnings' => []], $result);
        $this->assertDatabaseHas('segments', [
            'name' => 'Clients Transport',
            'scope' => 'client',
            'is_manual' => 0,
        ]);

        // Re-importing the same (case-differing) name updates rather than duplicates.
        // An empty filter_json cell must NOT null out the existing real filter —
        // SegmentService would resolve that to the whole scope audience — so the
        // scope still updates but the filter is preserved and a warning is raised.
        $again = $importer->parse($this->csv('CLIENTS TRANSPORT;prospect;;'), 'segments.csv');
        $this->assertTrue($again[0]['will_update']);

        $result = $importer->store($again);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('filtre existant conservé', $result['warnings'][0]);
        $this->assertDatabaseCount('segments', 1);
        $this->assertDatabaseHas('segments', ['name' => 'Clients Transport', 'scope' => 'prospect']);

        $segment = Segment::where('name', 'Clients Transport')->firstOrFail();
        $this->assertSame(['sector' => ['Transport']], $segment->filter, 'the existing filter must be preserved, not nulled.');
    }

    /**
     * An existing manual (pinned-contact) segment must never be silently
     * de-manualized by a re-import — a segment is a live campaign's
     * audience, and this importer only ever describes dynamic segments.
     */
    public function test_it_preserves_is_manual_and_warns_when_reimporting_a_manual_segment(): void
    {
        $manual = Segment::create([
            'name' => 'VIP Pinned',
            'scope' => 'client',
            'is_manual' => true,
            'filter' => null,
        ]);

        $importer = app(SegmentCsvImporter::class);
        $rows = $importer->parse($this->csv('VIP Pinned;prospect;{"sector":["Transport"]};'), 'segments.csv');

        $result = $importer->store($rows);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('segment manuel conservé', $result['warnings'][0]);

        $manual->refresh();
        $this->assertTrue((bool) $manual->is_manual, 'is_manual must stay true — never silently flipped by a re-import.');
        $this->assertSame('prospect', $manual->scope, 'scope itself is low-risk and still updates.');
    }

    public function test_it_rejects_an_invalid_scope(): void
    {
        $importer = app(SegmentCsvImporter::class);

        try {
            $importer->parse($this->csv('Segment invalide;bogus;;'), 'segments.csv');
            $this->fail('Expected a validation exception for an invalid scope.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv', $exception->errors());
            $this->assertStringContainsString('scope', $exception->errors()['csv'][0]);
        }

        $this->assertDatabaseCount('segments', 0);
    }
}
