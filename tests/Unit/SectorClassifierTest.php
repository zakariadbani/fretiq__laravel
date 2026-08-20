<?php

namespace Tests\Unit;

use App\Support\SectorClassifier;
use Tests\TestCase;

/**
 * SectorClassifierTest — unit tests for App\Support\SectorClassifier::canonical().
 *
 * resetCache() in setUp() forces the config('global.data.company_sectors')
 * fallback path (no DB / seeded `sectors` table needed here — see
 * SectorClassifier::canonicalSet(), which falls back to config when the
 * Sector query returns empty or throws).
 */
class SectorClassifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SectorClassifier::resetCache();
    }

    public function test_null_and_empty_input_return_null(): void
    {
        $this->assertNull(SectorClassifier::canonical(null));
        $this->assertNull(SectorClassifier::canonical(''));
        $this->assertNull(SectorClassifier::canonical('   '));
    }

    public function test_canonical_label_passes_through_unchanged(): void
    {
        $this->assertSame('Transport & Logistique', SectorClassifier::canonical('Transport & Logistique'));
    }

    public function test_idempotent(): void
    {
        $once = SectorClassifier::canonical('Fabricant de matériel médical');
        $twice = SectorClassifier::canonical($once);

        $this->assertSame($once, $twice);
    }

    public function test_unknown_string_passes_through_unchanged(): void
    {
        $this->assertSame('Some Brand New Vertical', SectorClassifier::canonical('Some Brand New Vertical'));
    }

    public function test_accent_case_and_curly_apostrophe_insensitivity(): void
    {
        $straight = SectorClassifier::canonical("Services d'information");
        $curly = SectorClassifier::canonical('Services d’information');

        $this->assertSame($straight, $curly);
    }

    /**
     * @dataProvider junkProvider
     */
    public function test_junk_maps_to_null(string $raw): void
    {
        $this->assertNull(SectorClassifier::canonical($raw));
    }

    public static function junkProvider(): array
    {
        return [
            'siege social' => ['Siège social'],
            'distributeur de billets' => ['Distributeur de billets'],
            'maison' => ['Maison'],
        ];
    }

    /**
     * @dataProvider regressionTrapProvider
     */
    public function test_regression_traps(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, SectorClassifier::canonical($raw));
    }

    public static function regressionTrapProvider(): array
    {
        return [
            // Trap 1: stem "medic" not "medical" — "médicaux" folds to "medicaux".
            'medic stem catches medicaux' => ['Fournisseur d’équipements médicaux', 'Matériel médical'],
            'medic stem generic services' => ['Health Care Providers & Services', 'Santé & Services médicaux'],

            // Trap 2: word-bound short tokens — bare "gas" must not match inside "magasin".
            'gas utilities maps to energie' => ['Gas Utilities', 'Énergie'],
            'magasin bare is junk not energie' => ['Magasin', null],
            'bois word bound' => ['Négociant en bois', 'BTP & Matériaux'],
            'media word bound' => ['Media', 'Télécommunications & Médias'],
            'food word bound product' => ['Food Products', 'Agroalimentaire'],

            // Trap 3: e-commerce must precede the internet|software|it services rule.
            'e-commerce precedes it rule' => ["Service d'e-commerce", 'E-commerce'],
            'it services falls to informatique' => ['IT Services', 'Informatique'],

            // Trap 4: cuisine belongs to hôtellerie, not agroalimentaire.
            'cuisine maps to hotellerie' => ['Magasin de matériel de cuisine', "Matériel d'hôtellerie"],

            // Trap 5: "Matériel industriel" merge — must map, not pass through unchanged.
            'materiel industriel merges into machines equipements' => ['Matériel industriel', 'Machines & Équipements industriels'],

            // Trap 6: "information technology" must precede the generic 'information' → Télécom rule.
            'information technology and services maps to informatique' => ['Information Technology and Services', 'Informatique'],
            'genuine telecom information still maps to telecom' => ["Services d'information", 'Télécommunications & Médias'],
        ];
    }
}
