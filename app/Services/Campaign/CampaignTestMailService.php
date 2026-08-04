<?php

namespace App\Services\Campaign;

use App\Mail\CampaignMailable;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class CampaignTestMailService
{
    public function __construct(private readonly CampaignsClient $campaignsClient) {}

    /**
     * Send one local preview to the current user without creating operational rows.
     */
    public function send(Campaign $campaign, User $user): void
    {
        if ($this->campaignsClient->driverName() !== 'local') {
            throw new InvalidArgumentException('L\'envoi test est disponible uniquement avec le pilote local.');
        }

        $campaign->loadMissing([
            'template.translations',
            'senderIdentity',
            'sequence.steps.template.translations',
        ]);

        if ($campaign->senderIdentity === null) {
            throw new InvalidArgumentException(html_entity_decode('Ajoutez une identit&eacute; d\'exp&eacute;diteur avant l\'envoi test.'));
        }

        if (! filled($user->email)) {
            throw new InvalidArgumentException('Votre compte ne possede aucune adresse email de test.');
        }

        $contact = new Contact([
            'name' => $user->name ?: 'Utilisateur test',
            'email' => $user->email,
        ]);
        $contact->id = 0;

        $trackingToken = hash('sha256', "campaign-test:{$campaign->id}:{$user->id}");
        $unsubscribeUrl = URL::signedRoute('unsubscribe.one-click', ['contact' => 0]);

        if ($campaign->schedule_type === 'sequence') {
            $step = $campaign->sequence?->steps->first();
            $template = $step?->template;
            if ($step === null || $template === null) {
                throw new InvalidArgumentException(html_entity_decode('Ajoutez une premi&egrave;re &eacute;tape avec un mod&egrave;le avant l\'envoi test.'));
            }

            $resolved = $template->resolveFor(null);
            $mailable = new SequenceStepMailable(
                step: $step,
                contact: $contact,
                subjectLine: $step->subject ?: $resolved['subject'],
                trackingToken: $trackingToken,
                unsubscribeUrl: $unsubscribeUrl,
                senderIdentity: $campaign->senderIdentity,
                resolvedHtml: $resolved['html_content'],
                language: $resolved['language'],
            );
        } else {
            $template = $campaign->template;
            if ($template === null) {
                throw new InvalidArgumentException(html_entity_decode('Ajoutez un mod&egrave;le avant l\'envoi test.'));
            }

            $resolved = $template->resolveFor(null);
            $subject = CampaignMailable::renderMergeTags(
                $campaign->subject ?: $resolved['subject'],
                $contact,
                $unsubscribeUrl,
            );
            $mailable = new CampaignMailable(
                campaign: $campaign,
                template: $template,
                contact: $contact,
                subjectLine: $subject,
                trackingToken: $trackingToken,
                unsubscribeUrl: $unsubscribeUrl,
                resolvedHtml: $resolved['html_content'],
                language: $resolved['language'],
            );
        }

        Mail::to($user->email)->send($mailable);
    }
}
