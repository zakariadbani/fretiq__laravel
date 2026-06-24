<?php

namespace Database\Seeders;

use App\Models\CampaignTemplate;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Database\Seeder;

/**
 * SequenceSeeder — persistent, prod-safe prospection sequences + their templates.
 *
 * Seeds 7 CampaignTemplate records and 4 Sequence records with their steps in
 * FK order: CampaignTemplate → Sequence → SequenceStep.
 *
 * All inserts use firstOrCreate with a stable logical key → idempotent across
 * migrate:fresh --seed runs. Nothing here can be dispatched by the scheduler:
 *   - Sequence: is_active=false
 *     (scheduler requires is_active=true to enroll contacts or send steps)
 */
class SequenceSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. CampaignTemplates ──────────────────────────────────────────────────

        $tplNewsletter = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Newsletter — Actualités fret'],
            [
                'subject'      => 'Actualités fret — tendances et tarifs du mois',
                'preview_text' => 'Le point mensuel sur le transport et la logistique par TCL France.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>Voici le point mensuel de <strong>TCL France</strong> sur le transport et la logistique : tendances de marché, évolution des tarifs de fret et capacités disponibles sur les principaux axes.</p>
<p>Ce mois-ci, nous mettons en avant notre service de <strong>groupage</strong> — une solution souple et économique pour vos envois à volume variable.</p>
<p>Un sujet vous intéresse ou vous avez une question sur vos flux ? Répondez simplement à ce message, nous serons ravis d'échanger.</p>
<p>Bonne lecture,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        $tplReactivationReprise = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Réactivation — Reprise de contact'],
            [
                'subject'      => 'Cela fait un moment — où en êtes-vous ?',
                'preview_text' => 'Reprenons contact sur vos besoins de transport.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>Cela fait un moment que nous n'avons pas eu l'occasion de collaborer, et je voulais reprendre contact.</p>
<p>Vos besoins en transport et logistique ont peut-être évolué depuis. Où en êtes-vous aujourd'hui sur vos flux import / export et votre affrètement ?</p>
<p>Je serais ravi de refaire le point avec vous et de voir comment <strong>TCL France</strong> peut vous accompagner.</p>
<p>Cordialement,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        $tplReactivationOffre = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Réactivation — Relance avec offre'],
            [
                'subject'      => 'Une offre pour relancer votre fret avec TCL France',
                'preview_text' => 'Tarifs revus et accompagnement dédié pour votre reprise.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>Je me permets de revenir vers vous : pour faciliter la reprise de notre collaboration, nous vous proposons un <strong>audit logistique gratuit</strong> de vos flux et une grille tarifaire revue sur vos axes prioritaires.</p>
<p>15 minutes suffisent pour identifier les premières optimisations. Seriez-vous disponible cette semaine ?</p>
<p>Cordialement,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        $tplSalonSuite = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Salon — Suite à notre rencontre'],
            [
                'subject'      => 'Ravi de notre échange sur le salon',
                'preview_text' => 'Faisons suite à notre rencontre.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>Ravi d'avoir échangé avec vous lors du salon. Comme convenu, je fais suite à notre rencontre.</p>
<p>Chez <strong>TCL France</strong>, nous accompagnons des entreprises comme la vôtre sur l'optimisation de leurs flux de fret — import, export, groupage et affrètement — avec un suivi personnalisé.</p>
<p>Je vous propose un court échange pour approfondir les points abordés. Quel créneau vous conviendrait ?</p>
<p>Cordialement,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        $tplSalonRelance = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Salon — Relance'],
            [
                'subject'      => 'Disponible pour un échange ?',
                'preview_text' => 'Un créneau pour approfondir ?',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>Je reviens vers vous suite à notre rencontre sur le salon. Je n'ai pas encore eu votre retour et je comprends que votre agenda soit chargé.</p>
<p>Seriez-vous disponible pour un échange de 15 minutes dans les prochains jours ? Je m'adapte à vos disponibilités.</p>
<p>Cordialement,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        $tplSalonDerniere = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Salon — Dernière proposition'],
            [
                'subject'      => 'Dernier message — restons en contact',
                'preview_text' => 'Je referme la boucle pour l\'instant.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p>N'ayant pas eu de retour, je ne souhaite pas vous solliciter davantage pour le moment.</p>
<p>La porte reste ouverte : dès que vous aurez un besoin de transport ou de logistique, n'hésitez pas à me recontacter — ce sera un plaisir de vous accompagner.</p>
<p>Bien cordialement,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        $tplAnnonce = CampaignTemplate::firstOrCreate(
            ['name' => 'Modèle Annonce — Nouvelle ligne'],
            [
                'subject'      => 'Nouvelle ligne TCL France : France – Maroc',
                'preview_text' => 'Une nouvelle solution pour vos flux.',
                'html_content' => <<<'HTML'
<p>Bonjour {{contact.name}},</p>
<p><strong>TCL France</strong> ouvre une nouvelle ligne régulière <strong>France – Maroc</strong> en groupage et lot complet.</p>
<p>Au programme : départs hebdomadaires, délais maîtrisés, prise en charge douane et suivi de bout en bout. Une solution idéale pour fiabiliser vos flux sur cet axe.</p>
<p>Vous expédiez ou importez sur le Maroc ? Répondez à ce message, nous vous communiquons les conditions détaillées.</p>
<p>Cordialement,<br>L'équipe TCL France</p>
<p style="font-size:11px;color:#888;">
Pour ne plus recevoir nos messages : <a href="{{unsubscribe_url}}">se désabonner</a>.
</p>
HTML,
            ],
        );

        // ── 2. Sequences + Steps ──────────────────────────────────────────────────

        // 2a. Newsletter mensuelle (no stop on reply — newsletter broadcast)
        $seqNewsletter = Sequence::firstOrCreate(
            ['name' => 'Newsletter mensuelle'],
            [
                'is_active'     => false,
                'stop_on_reply' => false,
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqNewsletter->id,
                'step_no'     => 1,
            ],
            [
                'delay_days'  => 0,
                'template_id' => $tplNewsletter->id,
                'subject'     => 'Actualités fret du mois',
            ],
        );

        // 2b. Réactivation client dormant
        $seqReactivation = Sequence::firstOrCreate(
            ['name' => 'Réactivation client dormant'],
            [
                'is_active'     => false,
                'stop_on_reply' => true,
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqReactivation->id,
                'step_no'     => 1,
            ],
            [
                'delay_days'  => 0,
                'template_id' => $tplReactivationReprise->id,
                'subject'     => 'Cela fait un moment',
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqReactivation->id,
                'step_no'     => 2,
            ],
            [
                'delay_days'  => 4,
                'template_id' => $tplReactivationOffre->id,
                'subject'     => 'Une offre pour relancer votre fret',
            ],
        );

        // 2c. Relance post-salon
        $seqSalon = Sequence::firstOrCreate(
            ['name' => 'Relance post-salon'],
            [
                'is_active'     => false,
                'stop_on_reply' => true,
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqSalon->id,
                'step_no'     => 1,
            ],
            [
                'delay_days'  => 0,
                'template_id' => $tplSalonSuite->id,
                'subject'     => 'Suite à notre rencontre',
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqSalon->id,
                'step_no'     => 2,
            ],
            [
                'delay_days'  => 2,
                'template_id' => $tplSalonRelance->id,
                'subject'     => 'Disponible pour un échange ?',
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqSalon->id,
                'step_no'     => 3,
            ],
            [
                'delay_days'  => 5,
                'template_id' => $tplSalonDerniere->id,
                'subject'     => 'Dernier message',
            ],
        );

        // 2d. Annonce nouvelle ligne
        $seqAnnonce = Sequence::firstOrCreate(
            ['name' => 'Annonce nouvelle ligne'],
            [
                'is_active'     => false,
                'stop_on_reply' => false,
            ],
        );

        SequenceStep::firstOrCreate(
            [
                'sequence_id' => $seqAnnonce->id,
                'step_no'     => 1,
            ],
            [
                'delay_days'  => 0,
                'template_id' => $tplAnnonce->id,
                'subject'     => 'Nouvelle ligne France – Maroc',
            ],
        );
    }
}
