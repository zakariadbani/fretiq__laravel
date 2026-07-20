<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\SenderIdentity;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Database\Seeder;

/**
 * DefaultProspectionSeeder — persistent, prod-safe reference data.
 *
 * Seeds the full dependency chain in FK order:
 *   SenderIdentity → CampaignTemplate → Segments → Sequence + Steps → Campaign.
 *
 * All inserts use firstOrCreate with a stable logical key → idempotent across
 * migrate:fresh --seed runs. Nothing here can be dispatched by the scheduler:
 *   - SenderIdentity: is_default=true, is_active=true (selectable FROM identity;
 *     does not itself send — Campaign stays is_active=false + next_run_at=null)
 *   - Campaign: is_active=false, next_run_at=null
 *     (scheduler requires status='active' + is_active=true + next_run_at IS NOT NULL)
 */
class DefaultProspectionSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Default SenderIdentity ─────────────────────────────────────────────
        $sender = SenderIdentity::firstOrCreate(
            ['email' => 'sales@tcltransport.com'],
            [
                'name'           => 'Sales — TCL France',
                'reply_to'       => null,
                'signature_html' => '<p>TCL France</p>',
                'is_default'     => true,
                'is_active'      => true,
            ],
        );

        // ── 2. Starter CampaignTemplate ───────────────────────────────────────────
        $template = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Prospection — Introduction'],
            [
                'subject'      => 'Optimisez votre chaîne logistique avec TCL France',
                'preview_text' => 'Une solution de transport sur mesure pour votre activité.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>

<p>Je me permets de vous contacter au sujet des services de transport et de logistique
proposés par <strong>TCL France</strong>.</p>

<p>Nous accompagnons des entreprises comme la vôtre dans l'optimisation de leurs flux
de fret — import, export, groupage et affrètement — avec un suivi personnalisé et
des tarifs compétitifs.</p>

<p>Seriez-vous disponible pour un échange de 15 minutes cette semaine afin de voir si
nous pouvons vous apporter de la valeur ?</p>

<p>Cordialement,<br>
L'équipe TCL France</p>
HTML,
            ],
        );

        // ── 3. Segments ───────────────────────────────────────────────────────────
        Segment::firstOrCreate(
            ['name' => 'Prospects France'],
            [
                'scope'  => 'prospect',
                'filter' => ['country' => ['FR']],
            ],
        );

        $prospectsSegment = Segment::where('name', 'Prospects France')->first();

        Segment::firstOrCreate(
            ['name' => 'Clients actifs'],
            [
                'scope'  => 'client',
                'filter' => null,
            ],
        );

        Segment::firstOrCreate(
            ['name' => 'Tous les prospects'],
            [
                'scope'  => 'prospect',
                'filter' => null,
            ],
        );

        // ── 4. Sequence + Steps ───────────────────────────────────────────────────
        $sequence = Sequence::firstOrCreate(
            ['name' => 'Séquence Prospection Standard'],
            [
                'is_active'     => false,
                'stop_on_reply' => true,
            ],
        );

        $steps = [
            ['step_no' => 1, 'delay_days' => 0, 'subject' => 'Introduction — TCL France'],
            ['step_no' => 2, 'delay_days' => 3, 'subject' => 'Relance — votre fret'],
            ['step_no' => 3, 'delay_days' => 7, 'subject' => 'Dernière relance'],
        ];

        foreach ($steps as $step) {
            SequenceStep::firstOrCreate(
                [
                    'sequence_id' => $sequence->id,
                    'step_no'     => $step['step_no'],
                ],
                [
                    'delay_days'  => $step['delay_days'],
                    'template_id' => $template->id,
                    'subject'     => $step['subject'],
                ],
            );
        }

        // ── 5. Example Campaign (safe draft — never dispatched) ───────────────────
        Campaign::firstOrCreate(
            ['name' => 'Campagne Exemple — Prospection FR'],
            [
                'segment_id'         => $prospectsSegment->id,
                'template_id'        => $template->id,
                'sender_identity_id' => $sender->id,
                'sequence_id'        => null,
                'subject'            => null,
                'schedule_type'      => 'one_shot',
                'is_active'          => false,
                'scheduled_at'       => null,
                'next_run_at'        => null,
                'driver'             => 'local',
            ],
        );
    }
}
