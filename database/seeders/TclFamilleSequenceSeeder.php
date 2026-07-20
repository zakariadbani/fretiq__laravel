<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CampaignTemplateTranslation;
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
 * Base records use firstOrCreate with stable logical keys; English translations
 * use updateOrCreate so copy and source hashes stay current. NOTHING seeded here is dispatchable until a human
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
        $englishTranslations = $this->englishTranslations();

        foreach ([1, 2, 3] as $n) {
            $tpl = [];

            foreach ($templates[$n] as $definition) {
                $template = CampaignTemplate::firstOrCreate(
                    ['name' => $definition['name']],
                    [
                        'subject'      => $definition['subject'],
                        'preview_text' => $definition['preview_text'],
                        'html_content' => $definition['html_content'],
                    ],
                );
                $hashes = $template->sourceHashes();
                $translation = $englishTranslations[$definition['name']];

                CampaignTemplateTranslation::updateOrCreate(
                    [
                        'campaign_template_id' => $template->id,
                        'language' => 'en',
                    ],
                    [
                        'subject' => $translation['subject'],
                        'preview_text' => $translation['preview_text'],
                        'html_content' => $translation['html_content'],
                        'is_ai_generated' => true,
                        'reviewed_at' => null,
                        'src_subject_hash' => $hashes['subject'],
                        'src_preview_hash' => $hashes['preview'],
                        'src_body_hash' => $hashes['body'],
                    ],
                );

                $tpl[] = $template;
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
                    "TclFamilleSequenceSeeder: sender identity sales@tcltransport.com introuvable — "
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

    /** @return array<string, array{subject: string, preview_text: string, html_content: string}> */
    private function englishTranslations(): array
    {
        return [
            'Famille 1 — Email 1 (J0) — Maîtrise de la contrainte' => [
                'subject' => 'Secure your sensitive imports from Europe — {{company.name}}',
                'preview_text' => 'Critical deadlines, sensitive goods and compliance: dedicated handling.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>When handling sensitive goods — healthcare, medical equipment, high-tech products or regulated instruments —
a customs clearance delay or supply-chain disruption is never trivial: deadlines are critical, goods are high-value
and certifications must be respected.</p>

<p>At <strong>TCL Transport</strong>, we support Moroccan importers sourcing from Europe, with dedicated handling for
high-value goods and products subject to strict regulations: traceability, temperature-controlled transport and
complete export/import documentation.</p>

<p>For your most urgent shipments, we are an <strong>IATA agent</strong> and work directly with airlines for both cargo
and express services. This enables us to secure tight deadlines without an additional intermediary.</p>

<p>Would you have 15 minutes this week to discuss your current flows and identify any friction points?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 1 — Email 2 (J+4) — Preuve concrète' => [
                'subject' => 'A recent air-freight shipment in practice',
                'preview_text' => 'Collection within 48 hours and end-to-end documentation tracking.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>Following up on my previous message: we recently managed an urgent shipment for an importer in your sector,
with collection required within 48 hours and complete documentation tracking through to final delivery.</p>

<p>This type of situation is common in healthcare and medical logistics and, more broadly, for all controlled goods.
If you face similar constraints, I can explain how we structure priority flows without systematically adding an
urgency surcharge.</p>

<p>We also operate <strong>customs-bonded warehouses (MEAD) in Tangier and Casablanca</strong>, helping streamline
customs clearance for the most tightly controlled products.</p>

<p>Would you like me to send you the details?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 1 — Email 3 (J+9) — Contenu expert' => [
                'subject' => 'What is changing in import customs controls',
                'preview_text' => 'A compliance checklist to help prevent customs holds.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>One development directly affecting your flows is the tightening of document checks for certain sensitive or
regulated products, for both imports and exports.</p>

<p>We have prepared a <strong>compliance checklist</strong> to anticipate these checks and prevent customs holds. I
would be glad to share it or simply discuss your current process.</p>

<p>For less urgent but regular flows, our <strong>FCL/LCL ocean-freight service</strong> remains a relevant complement
to air freight, offering better economics for volumes that can be planned.</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 1 — Email 4 (J+15) — CTA direct' => [
                'subject' => 'A complimentary review of your supply chain?',
                'preview_text' => 'A no-obligation 20-minute outside view of your logistics flows.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>I would like to offer a brief, no-obligation 20-minute review of your current import flows: lead times, recurring
bottlenecks and any non-compliance costs.</p>

<p>You will gain an independent view of your logistics, whether or not we subsequently work together.</p>

<p>If regulated storage is also a priority, we offer <strong>warehousing and inventory management (WMS, picking)</strong>
tailored to sensitive products.</p>

<p>What time would suit you this week or next?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 2 — Email 1 (J0) — Angle coût' => [
                'subject' => 'Optimise the cost of your Europe–Morocco flows',
                'preview_text' => 'Regular road groupage from Goussainville, Barcelona and Porto.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>On the Europe–Morocco corridor, many companies still pay a premium because groupage is not fully optimised or
shipping frequency is inconsistent.</p>

<p><strong>TCL Transport</strong> works with several businesses in your sector on this corridor, systematically
optimising the volume-to-cost ratio of industrial flows through road groupage, LCL and FCL.</p>

<p>We operate regular <strong>road groupage departures to Tangier and Casablanca</strong>: four departures per week
from our Goussainville platform in France, two to three per week from Barcelona and one weekly departure from Porto.</p>

<p>Would you be open to a quick comparison with your current solution?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 2 — Email 2 (J+4) — Preuve sociale chiffrée' => [
                'subject' => 'A practical example from your sector',
                'preview_text' => 'Higher load factors, lower unit costs and more reliable lead times.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>Here is a practical example involving industrial flows comparable to yours: by optimising load factors and
departure frequency from Europe, we helped a client significantly reduce unit transport costs while improving
delivery reliability.</p>

<p>For larger volumes, we also offer <strong>full truckload (FTL)</strong> for both imports and exports, alongside
our groupage service.</p>

<p>If your current volumes are suitable, I can prepare a quick simulation based on your actual flows.</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 2 — Email 3 (J+9) — Urgence & saisonnalité' => [
                'subject' => 'Plan ahead for your volume peaks',
                'preview_text' => 'Avoid spot-rate increases and capacity constraints.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>Your sector typically experiences volume peaks at certain times of year. Planning ahead helps avoid spot-rate
increases and delays caused by limited capacity.</p>

<p>For larger volumes, our <strong>FCL/LCL ocean-freight service</strong> is often the most economical option. We are
currently planning capacity allocations for the coming months, including departures from Asia and the United States.</p>

<p>Would you like to discuss it?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 2 — Email 4 (J+15) — Offre tarifaire' => [
                'subject' => 'A complimentary rate simulation for {{company.name}}',
                'preview_text' => 'A no-obligation cost simulation within 48 hours.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>I would like to offer a <strong>cost simulation within 48 hours</strong> for your current industrial flows, with
no commitment required. It will give you a concrete benchmark.</p>

<p>Beyond transport, we also provide a complete logistics service
(<strong>warehousing, inventory management, WMS and picking</strong>) that can integrate with your existing supply
chain when needed.</p>

<p>I only need your approximate volumes and usual shipping frequency.</p>

<p>May I call you this week to collect those details?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 3 — Email 1 (J0) — Gestion de projet' => [
                'subject' => 'Keep project deadlines on track without logistics surprises',
                'preview_text' => 'Sourcing, transport, customs and delivery aligned with your schedule.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>An equipment project often depends on a fixed date — opening, commissioning or site delivery — that leaves no
room for logistics delays.</p>

<p><strong>TCL Transport</strong> supports these projects with dedicated end-to-end coordination: sourcing, transport,
customs clearance and final delivery aligned with your site schedule, using <strong>FCL/LCL ocean freight</strong> or
<strong>full truckload (FTL)</strong> according to the equipment involved.</p>

<p>Do you have a current project where a brief logistics review would be useful?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 3 — Email 2 (J+4) — Cas concret' => [
                'subject' => 'A project managed from end to end',
                'preview_text' => 'Multiple deliveries synchronised to a demanding site schedule.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>To illustrate our approach, we recently coordinated all transport and logistics for a client delivering an
equipment project comparable to yours, with several deliveries synchronised to a demanding site schedule.</p>

<p>This coordination requires full visibility across every link in the chain — our core expertise in project logistics.
Our <strong>customs-bonded warehouses (MEAD) in Tangier and Casablanca</strong> also provide flexible temporary storage
until the site's delivery window opens.</p>

<p>Would you like to learn more about our project-management method?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 3 — Email 3 (J+9) — Accompagnement amont' => [
                'subject' => 'Bring logistics into the purchasing phase',
                'preview_text' => 'Anticipate logistics constraints from the sourcing stage.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>One frequently underestimated aspect of equipment projects is logistics planning during sourcing and purchasing.
Addressing constraints early prevents extra costs or delays from emerging too late in the project.</p>

<p>We provide this upstream support and, when needed, <strong>warehousing and inventory management (WMS, picking)</strong>
while your project progresses — helping secure future projects from the definition stage onward.</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
            'Famille 3 — Email 4 (J+15) — CTA planning' => [
                'subject' => 'Let us discuss your next project',
                'preview_text' => '20 minutes to anticipate lead times, customs and site coordination.',
                'html_content' => <<<HTML
<p>Hello {{contact.first_name}},</p>

<p>Do you have an upcoming project that would benefit from logistics planning? I suggest a 20-minute discussion to
anticipate the critical points: lead times, customs and site coordination.</p>

<p>For a practical view of our facilities, you can also take a 3D tour of our warehouses:
<a href="https://tcltransport.com/visite-virtuelle-360/entrepot/">https://tcltransport.com/visite-virtuelle-360/entrepot/</a></p>

<p>What time would work best for you?</p>

<p>Kind regards,<br>
The TCL Transport team</p>

HTML,
            ],
        ];
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
