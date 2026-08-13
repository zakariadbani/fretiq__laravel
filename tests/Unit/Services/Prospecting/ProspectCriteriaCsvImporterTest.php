<?php

namespace Tests\Unit\Services\Prospecting;

use App\Services\Prospecting\ProspectCriteriaCsvImporter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProspectCriteriaCsvImporterTest extends TestCase
{
    public function test_it_normalizes_bom_delimited_rows_and_forces_safe_values(): void
    {
        $csv = "\xEF\xBB\xBFname;ai_target;ai_exclude;sectors;countries;company_sizes;target_positions;daily_limit;is_active;auto_run;run_at_hour;contact_limit;min_score_enrich;auto_enrich\n"
            ."  PME Maroc  ;Transport | Transport | Industrie;  ;Logistique | Logistique ; MA | FR ; 11-50 | 11-50 ; Directeur | Directeur ; ;false;0;; ; ;off\n";

        $rows = app(ProspectCriteriaCsvImporter::class)->parse($csv, 'criteres.csv');

        $this->assertSame([[
            'name' => 'PME Maroc',
            'ai_target' => 'Transport | Transport | Industrie',
            'ai_exclude' => null,
            'sectors' => ['Logistique'],
            'countries' => ['MA', 'FR'],
            'company_sizes' => ['11-50'],
            'target_positions' => ['Directeur'],
            'daily_limit' => 5,
            'contact_limit' => 10,
            'min_score_enrich' => 50,
            'run_at_hour' => null,
            'is_active' => false,
            'auto_run' => false,
            'auto_enrich' => false,
        ]], $rows);
    }

    public function test_it_rejects_unsafe_or_unsafe_activation_input(): void
    {
        $header = 'name,ai_target,ai_exclude,sectors,countries,company_sizes,target_positions,daily_limit,is_active,auto_run,run_at_hour,contact_limit,min_score_enrich,auto_enrich';

        try {
            app(ProspectCriteriaCsvImporter::class)->parse($header."\nActif,,,,,,,,true,,,,,", 'criteres.csv');
            $this->fail('Expected a validation exception for an active import.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv', $exception->errors());
            $this->assertStringContainsString('Ligne 2', $exception->errors()['csv'][0]);
        }
    }

    public function test_it_preserves_quoted_multiline_ai_fields(): void
    {
        $csv = "name;ai_target;ai_exclude;sectors;countries;company_sizes;target_positions;daily_limit;is_active;auto_run;run_at_hour;contact_limit;min_score_enrich;auto_enrich\n"
            .implode(';', [
                'Multiligne', '"Cible ligne 1'."\n".'Cible ligne 2"', '"Exclure ligne 1'."\n".'Exclure ligne 2"',
                '', '', '', '', '', 'false', 'false', '', '10', '50', 'false',
            ])."\n";

        $rows = app(ProspectCriteriaCsvImporter::class)->parse($csv, 'criteres.csv');

        $this->assertSame("Cible ligne 1\nCible ligne 2", $rows[0]['ai_target']);
        $this->assertSame("Exclure ligne 1\nExclure ligne 2", $rows[0]['ai_exclude']);
    }

    public function test_it_counts_invalid_records_toward_the_data_row_limit(): void
    {
        $header = 'name;ai_target;ai_exclude;sectors;countries;company_sizes;target_positions;daily_limit;is_active;auto_run;run_at_hour;contact_limit;min_score_enrich;auto_enrich';
        $invalid = array_fill(0, count(ProspectCriteriaCsvImporter::HEADERS), '');
        $invalid[8] = 'true';
        $rows = array_fill(0, 101, implode(';', $invalid));

        try {
            app(ProspectCriteriaCsvImporter::class)->parse($header."\n".implode("\n", $rows), 'criteres.csv');
            $this->fail('Expected row-limit validation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('100 lignes', implode(' ', $exception->errors()['csv']));
        }
    }

    public function test_it_rejects_executable_magic(): void
    {
        $this->expectException(ValidationException::class);

        app(ProspectCriteriaCsvImporter::class)->parse('MZ not a CSV', 'criteres.csv');
    }

    public function test_it_rejects_missing_or_duplicate_headers(): void
    {
        $csv = "name;name\nA;B\n";

        $this->expectException(ValidationException::class);
        app(ProspectCriteriaCsvImporter::class)->parse($csv, 'criteres.csv');
    }

    public function test_template_is_bom_prefixed_header_only_semicolon_csv(): void
    {
        $template = app(ProspectCriteriaCsvImporter::class)->template();

        $this->assertStringStartsWith("\xEF\xBB\xBFname;ai_target;ai_exclude;", $template);
        $this->assertSame(1, substr_count($template, "\n"));
    }
}
