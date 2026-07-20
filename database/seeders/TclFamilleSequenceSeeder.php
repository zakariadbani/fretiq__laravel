<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Database\Seeder;

/**
 * TclFamilleSequenceSeeder — the 3 "familles" de prospection TCL Transport.
 *
 * Source of truth for the copy: docs/plan prospection.txt (3 familles × 4 emails
 * français à J0 / J+4 / J+9 / J+15). Audience = importateurs marocains qui
 * achètent en Europe, découverts par les 3 critères de ProspectCriteriaSeeder.
 *
 * Seeds, per famille, in strict FK order:
 *   CampaignTemplate ×4 → Sequence + SequenceStep ×4 → Segment → Campaign
 * Totals: 12 templates, 3 sequences (12 steps), 3 segments, 3 campaigns.
 *
 * All inserts use firstOrCreate with a stable logical key → idempotent across
 * migrate:fresh --seed runs. NOTHING seeded here is dispatchable until a human
 * activates it in the back-office:
 *   - Sequence: is_active = false (scheduler requires is_active=true to enroll
 *     contacts or send steps)
 *   - Campaign: is_active = false, scheduled_at = null, next_run_at = null
 *     (scheduler requires is_active=true + next_run_at IS NOT NULL)
 *
 * Merge tags: the renderer (App\Mail\Concerns\RendersTrackedHtml) supports
 * five personalization tokens — {{contact.name}}, {{contact.first_name}},
 * {{contact.email}}, {{company.name}}, {{company.sector}}.
 * {{company.sector}} is deliberately NOT used: companies.sector holds free-form
 * ENGLISH Hunter values and is frequently empty, so it would inject "Health Care"
 * into a French sentence or leave a hole. Famille-appropriate French wording is
 * baked into the copy instead.
 */
class TclFamilleSequenceSeeder extends Seeder
{
    public function run(): void
    {
        // ── 0. Dependencies ───────────────────────────────────────────────────────
        // Seeded by DefaultProspectionSeeder / ProspectCriteriaSeeder. Both are
        // resolved defensively: a missing sender means we skip the campaigns
        // (sender_identity_id is NOT NULL with a RESTRICT FK); a missing criteria
        // just leaves criteria_id out of the segment filter.
        $sender = SenderIdentity::where('email', 'sales@tcltransport.com')->first();

        $criteriaIds = [
            1 => ProspectCriteria::where('name', 'Famille 1 — Urgence & Réglementation (Importateurs MA)')->value('id'),
            2 => ProspectCriteria::where('name', 'Famille 2 — Volume & Récurrence (Importateurs MA)')->value('id'),
            3 => ProspectCriteria::where('name', 'Famille 3 — Projets & Chantiers (Importateurs MA)')->value('id'),
        ];

        $sectors = [
            1 => [
                'Informatique',
                'Matériel médical',
                'Laboratoires pharmaceutiques',
                'Équipementiers aéronautique',
                'Instruments de mesure',
                'Dentaire',
                'Optique',
            ],
            2 => [
                'Matériel industriel',
                'Isolation thermique & panneaux sandwich',
                'Équipementiers automobiles',
                'Climatisation',
                'Mobilier',
                'Électroménager',
                'Lubrifiants & pétrole',
            ],
            3 => [
                'Traitement des eaux',
                'Matériel d\'hôtellerie',
                'Cosmétique & Parfumerie',
            ],
        ];

        $libelles = [
            1 => 'Urgence & Réglementation',
            2 => 'Volume & Récurrence',
            3 => 'Projets & Chantiers',
        ];

        // ── 1. Templates ──────────────────────────────────────────────────────────
        $templates = [
            1 => $this->famille1Templates(),
            2 => $this->famille2Templates(),
            3 => $this->famille3Templates(),
        ];

        foreach ([1, 2, 3] as $n) {
            $tpl = [];

            foreach ($templates[$n] as $definition) {
                $tpl[] = CampaignTemplate::firstOrCreate(
                    ['name' => $definition['name']],
                    [
                        'subject'      => $definition['subject'],
                        'preview_text' => $definition['preview_text'],
                        'html_content' => $definition['html_content'],
                    ],
                );
            }

            // ── 2. Sequence + Steps ───────────────────────────────────────────────
            $sequence = Sequence::firstOrCreate(
                ['name' => 'Famille ' . $n . ' — ' . $libelles[$n]],
                [
                    'is_active'     => false,
                    'stop_on_reply' => true,
                ],
            );

            // delay_days is the gap from the PREVIOUS step, NOT a cumulative offset.
            // [0, 4, 5, 6] therefore lands on J0, J+4, J+9, J+15.
            $delays = [0, 4, 5, 6];

            foreach ($delays as $i => $delay) {
                SequenceStep::firstOrCreate(
                    [
                        'sequence_id' => $sequence->id,
                        'step_no'     => $i + 1,
                    ],
                    [
                        'delay_days'  => $delay,
                        'template_id' => $tpl[$i]->id,
                        // subject stays null so the template subject wins.
                        'subject'     => null,
                    ],
                );
            }

            // ── 3. Segment ────────────────────────────────────────────────────────
            // sector OR criteria_id, AND country. criteria_id support lands with
            // SegmentService; the key is seeded regardless so no re-seed is needed.
            $filter = [
                'sector'      => $sectors[$n],
                'criteria_id' => $criteriaIds[$n] !== null ? [$criteriaIds[$n]] : [],
                'country'     => ['MA'],
            ];

            $segment = Segment::firstOrCreate(
                ['name' => 'Importateurs MA — Famille ' . $n],
                [
                    'scope'  => 'prospect',
                    'filter' => $filter,
                ],
            );

            // ── 4. Campaign (safe draft — never dispatched) ───────────────────────
            if ($sender === null) {
                $this->command?->warn(
                    "TclFamilleSequenceSeeder: sender identity mnejjar@tcl.ma introuvable — "
                    . "campagne Famille {$n} non créée."
                );

                continue;
            }

            Campaign::firstOrCreate(
                ['name' => 'Campagne Famille ' . $n . ' — ' . $libelles[$n]],
                [
                    'segment_id'         => $segment->id,
                    // template_id is NOT NULL with a RESTRICT FK → point it at the
                    // famille's Email 1; the sequence drives the actual sending.
                    'template_id'        => $tpl[0]->id,
                    'sender_identity_id' => $sender->id,
                    'sequence_id'        => $sequence->id,
                    'subject'            => null,
                    'schedule_type'      => 'sequence',
                    'is_active'          => false,
                    'scheduled_at'       => null,
                    'next_run_at'        => null,
                    'driver'             => 'local',
                ],
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // FAMILLE 1 — Urgence & Réglementation
    // Informatique, matériel médical, labos pharma, aéronautique, instruments de
    // mesure, dentaire, optique. Angle : maîtrise de la contrainte réglementaire
    // et des délais critiques.
    // ──────────────────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, string>> */
    private function famille1Templates(): array
    {
        return [
            [
                'name'         => 'Famille 1 — Email 1 (J0) — Maîtrise de la contrainte',
                'subject'      => 'Sécuriser vos importations sensibles depuis l\'Europe — {{company.name}}',
                'preview_text' => 'Délais critiques, produits sensibles, conformité : une gestion dédiée.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Sur des produits sensibles — santé, médical, high-tech, instruments réglementés —
un retard de dédouanement ou une rupture de chaîne logistique n'est jamais anodin :
délai critique, marchandise à forte valeur, certification à respecter.</p>

<p>Chez <strong>TCL Transport</strong>, nous accompagnons plusieurs importateurs
marocains sur leurs approvisionnements depuis l'Europe, avec une gestion dédiée aux
marchandises à forte valeur ou soumises à réglementation stricte : traçabilité,
température dirigée, documentation export/import complète.</p>

<p>Pour vos flux les plus urgents, nous sommes <strong>agent IATA</strong> et opérons
directement avec les compagnies aériennes, en cargo comme en express — ce qui nous
permet de sécuriser des délais serrés sans intermédiaire supplémentaire.</p>

<p>Auriez-vous 15 minutes cette semaine pour échanger sur vos flux actuels et
identifier les points de friction ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 1 — Email 2 (J+4) — Preuve concrète',
                'subject'      => 'Un exemple concret de transport aérien réalisé',
                'preview_text' => 'Prise en charge sous 48h et suivi documentaire de bout en bout.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Pour rebondir sur mon précédent message : nous avons récemment géré, pour un
importateur de votre secteur, un envoi urgent nécessitant une prise en charge sous
48h avec suivi documentaire complet jusqu'à la livraison finale.</p>

<p>Ce type de situation revient régulièrement sur les flux santé &amp; médical et,
plus largement, sur toutes les marchandises contrôlées. Si c'est également votre cas,
je peux vous partager comment nous structurons ces flux prioritaires — sans surcoût
systématique lié à l'urgence.</p>

<p>Nous disposons également de <strong>magasins sous douane (MEAD) à Tanger et à
Casablanca</strong>, ce qui permet de fluidifier les opérations de dédouanement sur
les produits les plus contrôlés.</p>

<p>Souhaitez-vous que je vous envoie le détail ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 1 — Email 3 (J+9) — Contenu expert',
                'subject'      => 'Ce qui change sur les contrôles douaniers à l\'import',
                'preview_text' => 'Une checklist de conformité pour éviter les blocages en douane.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Un point qui impacte directement vos flux : le renforcement des contrôles
documentaires sur certains produits sensibles ou réglementés, à l'import comme à
l'export.</p>

<p>Nous avons formalisé une <strong>checklist de conformité</strong> pour anticiper
ces contrôles et éviter les blocages en douane — je me tiens à votre disposition si
vous souhaitez la consulter, ou simplement échanger sur votre process actuel.</p>

<p>Pour les flux moins urgents mais réguliers, notre offre <strong>maritime FCL/LCL</strong>
reste une alternative pertinente en complément de l'aérien, avec un meilleur coût sur
les volumes planifiables.</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 1 — Email 4 (J+15) — CTA direct',
                'subject'      => 'Un diagnostic gratuit de votre chaîne logistique ?',
                'preview_text' => '20 minutes, sans engagement, pour un regard extérieur sur vos flux.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Je vous propose un point rapide — 20 minutes, sans engagement — pour passer en revue
vos flux d'importation actuels : délais, points de blocage récurrents, coûts de
non-conformité éventuels.</p>

<p>Cela vous permettra d'avoir un regard extérieur sur votre logistique, que nous
travaillions ensemble ou non par la suite.</p>

<p>Si le stockage réglementé fait partie de vos enjeux, sachez que nous proposons aussi
une offre d'<strong>entreposage et de gestion de stock (WMS, picking)</strong> adaptée
aux produits sensibles.</p>

<p>Quel créneau vous conviendrait cette semaine ou la semaine prochaine ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // FAMILLE 2 — Volume & Récurrence
    // Matériel industriel, isolation/panneaux sandwich, équipementiers automobiles,
    // climatisation, mobilier, électroménager, lubrifiants. Angle : coût au volume,
    // récurrence des départs, saisonnalité.
    // ──────────────────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, string>> */
    private function famille2Templates(): array
    {
        return [
            [
                'name'         => 'Famille 2 — Email 1 (J0) — Angle coût',
                'subject'      => 'Optimiser le coût de vos flux Europe – Maroc',
                'preview_text' => 'Groupage routier régulier depuis Goussainville, Barcelone et Porto.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Sur l'axe Europe – Maroc, beaucoup d'entreprises paient encore un surcoût lié à une
mauvaise optimisation du groupage ou à des ruptures dans la récurrence des expéditions.</p>

<p><strong>TCL Transport</strong> travaille avec plusieurs acteurs de votre secteur sur
ce corridor et optimise systématiquement le ratio volume/coût sur vos flux industriels,
que ce soit en groupage routier, en LCL ou en FCL.</p>

<p>Nous opérons notamment des départs réguliers en <strong>groupage routier vers Tanger
et Casablanca</strong> : 4 départs par semaine depuis notre plateforme de Goussainville
(France), 2 à 3 départs par semaine depuis Barcelone, et 1 départ hebdomadaire depuis
Porto.</p>

<p>Seriez-vous ouvert à une comparaison rapide avec votre solution actuelle ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 2 — Email 2 (J+4) — Preuve sociale chiffrée',
                'subject'      => 'Un exemple concret dans votre secteur',
                'preview_text' => 'Taux de remplissage optimisé, coût unitaire réduit, délais fiabilisés.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Un exemple concret sur des flux industriels comparables aux vôtres : en optimisant le
taux de remplissage et la fréquence des départs depuis l'Europe, nous avons permis à un
client de réduire significativement son coût de transport unitaire, tout en gagnant en
fiabilité de délai.</p>

<p>Pour les volumes plus conséquents, nous proposons également du <strong>complet (FTL)</strong>
à l'import comme à l'export, en complément du groupage.</p>

<p>Si vos volumes actuels s'y prêtent, je peux vous faire une simulation rapide sur vos
flux réels.</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 2 — Email 3 (J+9) — Urgence & saisonnalité',
                'subject'      => 'Anticiper vos pics de volume',
                'preview_text' => 'Éviter les hausses de tarif spot et la saturation des capacités.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Votre secteur connaît généralement des pics de flux à certaines périodes de l'année.
Anticiper ces pics permet d'éviter les hausses de tarif spot et les retards liés à la
saturation des capacités.</p>

<p>Sur les plus gros volumes, notre offre <strong>maritime FCL/LCL</strong> reste souvent
la solution la plus économique — nous planifions actuellement les allocations de capacité
pour les prochains mois, y compris depuis l'Asie ou les États-Unis.</p>

<p>Voulez-vous qu'on en discute ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 2 — Email 4 (J+15) — Offre tarifaire',
                'subject'      => 'Une simulation tarifaire gratuite pour {{company.name}}',
                'preview_text' => 'Une simulation de coût sous 48h, sans engagement.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Je vous propose une <strong>simulation de coût sous 48h</strong> sur vos flux
industriels actuels — sans engagement de votre part. Cela vous donnera une base de
comparaison concrète.</p>

<p>Au-delà du transport, nous proposons aussi une offre logistique complète
(<strong>entreposage, gestion de stock, WMS, picking</strong>) qui peut s'intégrer à
votre chaîne existante si besoin.</p>

<p>Il me suffit de connaître vos volumes approximatifs et votre fréquence d'expédition
habituelle.</p>

<p>Puis-je vous appeler cette semaine pour recueillir ces quelques informations ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────────
    // FAMILLE 3 — Projets & Chantiers
    // Traitement des eaux, matériel d'hôtellerie, cosmétique & parfumerie.
    // Angle : date fixe de mise en service, coordination de planning chantier.
    // ──────────────────────────────────────────────────────────────────────────────

    /** @return array<int, array<string, string>> */
    private function famille3Templates(): array
    {
        return [
            [
                'name'         => 'Famille 3 — Email 1 (J0) — Gestion de projet',
                'subject'      => 'Tenir vos délais projet sans imprévu logistique',
                'preview_text' => 'Sourcing, transport, douane et livraison calés sur votre planning.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Un projet d'équipement repose souvent sur une date fixe — ouverture, mise en service,
livraison chantier — qui ne tolère aucun retard côté logistique.</p>

<p><strong>TCL Transport</strong> accompagne ce type de projets avec un suivi dédié de
bout en bout : sourcing, transport, dédouanement et livraison finale coordonnée avec
votre calendrier chantier, en <strong>maritime FCL/LCL</strong> comme en
<strong>complet (FTL)</strong> selon la nature des équipements.</p>

<p>Avez-vous un projet en cours pour lequel un point rapide pourrait être utile ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 3 — Email 2 (J+4) — Cas concret',
                'subject'      => 'Un projet géré de bout en bout',
                'preview_text' => 'Plusieurs livraisons synchronisées sur un planning chantier serré.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Pour illustrer notre approche : nous avons récemment coordonné, pour un client menant
un projet d'équipement comparable au vôtre, l'ensemble du transport et de la logistique,
avec plusieurs étapes de livraison synchronisées à un planning chantier serré.</p>

<p>Ce type de coordination demande une visibilité complète sur chaque maillon — c'est
notre cœur de métier sur les projets. Nos <strong>magasins sous douane (MEAD) à Tanger
et à Casablanca</strong> nous permettent aussi de gérer un stockage temporaire flexible
en attendant la fenêtre de livraison chantier.</p>

<p>Souhaitez-vous en savoir plus sur notre méthode de gestion de projet ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 3 — Email 3 (J+9) — Accompagnement amont',
                'subject'      => 'Impliquer la logistique dès la phase d\'achat',
                'preview_text' => 'Anticiper la contrainte logistique dès le sourcing.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Un point souvent sous-estimé sur les projets d'équipement : intégrer la contrainte
logistique dès la phase de sourcing et d'achat permet d'éviter des surcoûts ou des
retards découverts trop tard dans le projet.</p>

<p>Nous proposons cet accompagnement en amont, incluant si besoin l'<strong>entreposage
et la gestion de stock (WMS, picking)</strong> le temps que votre projet avance — de quoi
sécuriser vos prochains projets dès la phase de définition.</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
            [
                'name'         => 'Famille 3 — Email 4 (J+15) — CTA planning',
                'subject'      => 'Parlons de votre prochain projet',
                'preview_text' => '20 minutes pour anticiper délais, douane et coordination chantier.',
                'html_content' => <<<HTML
<p>Bonjour {{contact.first_name}},</p>

<p>Avez-vous un projet à venir pour lequel un point de planification logistique serait
utile ? Je vous propose un échange de 20 minutes pour anticiper les points critiques :
délais, douane, coordination chantier.</p>

<p>Pour vous donner un aperçu concret de nos installations, vous pouvez aussi visiter nos
entrepôts en 3D :
<a href="https://tcltransport.com/visite-virtuelle-360/entrepot/">https://tcltransport.com/visite-virtuelle-360/entrepot/</a></p>

<p>Quel serait le meilleur moment pour vous ?</p>

<p>Cordialement,<br>
L'équipe TCL Transport</p>

HTML,
            ],
        ];
    }
}
