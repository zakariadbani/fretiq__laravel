<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use App\Models\Demande;
use App\Models\InboxEmail;
use App\Models\User;
use App\Services\Demande\DemandeCaptureService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InboxTriageService
{
    public const ACTIONS = ['interested', 'not_interested', 'automatic'];

    public function __construct(
        private readonly DemandeCaptureService $demandeCaptureService,
        private readonly ReplyRecordingService $replyRecording,
    ) {}

    public function triage(InboxEmail $email, string $action, User $actor): bool
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Action de tri invalide.');
        }

        return DB::transaction(function () use ($email, $action, $actor): bool {
            $email = InboxEmail::query()
                ->with(['contact', 'campaignRecipient.run.campaign', 'sequenceStepSend.enrollment'])
                ->lockForUpdate()
                ->findOrFail($email->id);

            if ($email->processed_at !== null) {
                return false;
            }

            if ($action === 'interested') {
                if (! $actor->can('create demandes')) {
                    abort(403);
                }
                if ($email->contact === null) {
                    throw new InvalidArgumentException('Associez ce message a un contact avant de creer une demande.');
                }

                if ($email->demande_id === null) {
                    $contact = Contact::query()->lockForUpdate()->findOrFail($email->contact_id);
                    $recipient = $email->campaignRecipient;
                    $campaign = $recipient?->run?->campaign;
                    $sequenceSend = $email->sequenceStepSend;
                    $enrollment = $sequenceSend?->enrollment;
                    $attribution = [
                        'campaign_id' => $campaign?->id ?? $enrollment?->campaign_id,
                        'campaign_run_id' => $recipient?->campaign_run_id ?? $sequenceSend?->campaign_run_id,
                        'sequence_id' => $campaign?->sequence_id ?? $enrollment?->sequence_id,
                    ];
                    $demande = null;
                    if (array_filter($attribution, fn ($id) => $id !== null) !== []) {
                        $demande = Demande::query()
                            ->where('contact_id', $contact->id)
                            ->where('kind', 'reply')
                            ->where($attribution)
                            ->first();
                    }

                    if ($demande === null) {
                        $demande = $this->demandeCaptureService->capture(
                            $contact,
                            $attribution,
                            'reply',
                            trim(($email->subject ?? '')."\n\n".($email->body_text ?? '')),
                        );
                    }
                    $email->demande_id = $demande->id;
                }

                $this->replyRecording->record($email);
            } elseif ($action === 'not_interested') {
                $this->replyRecording->record($email);
            }

            $email->fill([
                'triage_action' => $action,
                'triaged_by' => $actor->id,
                'status' => $action === 'interested' ? InboxEmail::STATUS_TRAITE : InboxEmail::STATUS_IGNORE,
                'processed_at' => now(),
            ])->save();

            return true;
        });
    }

}
