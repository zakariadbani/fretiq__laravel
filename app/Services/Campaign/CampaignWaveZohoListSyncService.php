<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Exceptions\ZohoInvalidRecipientException;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Models\Suppression;
use App\Services\Zoho\ZohoRecipientListGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Mirrors one frozen paced-sequence wave into its own Zoho recipient list. */
class CampaignWaveZohoListSyncService
{
    public function __construct(
        private readonly ZohoRecipientListGateway $gateway,
        private readonly SequenceWaveService $waveService,
    ) {}

    /** @return array{list_key: string, list_name: string, contacts: int} */
    public function sync(CampaignRun $run): array
    {
        $run->loadMissing(['campaign', 'sequenceStep', 'recipients.contact.company']);
        if (! str_starts_with($run->occurrence_key, 'sequence-wave-')) {
            throw new \InvalidArgumentException('Cette execution n\'est pas une vague de sequence.');
        }

        if ($this->waveService->isDeferred($run)) {
            return [
                'list_key' => (string) ($run->zoho_list_key ?? ''),
                'list_name' => $this->listName($run),
                'contacts' => $run->recipients->where('status', 'queued')->count(),
            ];
        }
        if ($this->waveService->hasPendingVerification($run)) {
            return [
                'list_key' => (string) ($run->zoho_list_key ?? ''),
                'list_name' => $this->listName($run),
                'contacts' => $run->recipients->where('status', 'queued')->count(),
            ];
        }

        $target = [];
        foreach ($this->waveService->eligibleContacts($run) as $contact) {
            $email = mb_strtolower(trim((string) $contact->email));
            if ($email === '') {
                continue;
            }
            $target[$email] = [
                'Contact Email' => $email,
                'First Name' => (string) ($contact?->name ?? ''),
                'Company' => (string) ($contact?->company?->name ?? ''),
            ];
        }
        ksort($target);
        if ($target === []) {
            return $this->finishEmpty($run, '', $this->listName($run));
        }

        $listName = $this->listName($run);
        $listKey = trim((string) $run->zoho_list_key);
        if ($listKey === '') {
            $listKey = trim($this->gateway->ensureCampaignList($run->campaign_id, $listName, array_values($target)));
            if ($listKey === '') {
                throw new \RuntimeException('Zoho n\'a retourne aucune cle pour la liste de vague.');
            }
            $run->forceFill(['zoho_list_key' => $listKey])->save();
        }

        $before = $this->emailsByNormalizedValue($this->gateway->listEmails($listKey));
        $missing = array_diff_key($target, $before);
        $toPush = trim((string) config('services.zoho.campaigns.topic_id')) !== '' ? $target : $missing;

        // Peel loop: never write per-rejection here — just tally acceptances and
        // collect rejections, so the corroboration guard below can see the whole
        // picture (whole wave nuked vs. one-off) before anything hits the DB.
        $accepted = 0;
        /** @var array<string, ZohoInvalidRecipientException> $rejected */
        $rejected = [];
        foreach (array_chunk(array_values($toPush), 10) as $chunk) {
            while ($chunk !== []) {
                try {
                    $this->gateway->addContacts($listKey, $chunk);
                    $accepted += count($chunk);
                    break;
                } catch (ZohoInvalidRecipientException $exception) {
                    $email = $exception->email;
                    $chunkContainsEmail = collect($chunk)->contains(
                        fn (array $contact): bool => mb_strtolower(trim((string) ($contact['Contact Email'] ?? ''))) === $email,
                    );

                    if (! $chunkContainsEmail || ! isset($target[$email])) {
                        throw $exception;
                    }

                    $rejected[$email] = $exception;
                    unset($target[$email]);
                    $chunk = array_values(array_filter(
                        $chunk,
                        fn (array $contact): bool => mb_strtolower(trim((string) ($contact['Contact Email'] ?? ''))) !== $email,
                    ));
                }
            }
        }

        // Corroboration guard, before any write. An unverified code (not
        // live-proven address-level) is only trusted per-contact if at least one
        // other contact in this wave was accepted — otherwise this smells systemic
        // (e.g. a bad topic_id) rather than a handful of bad addresses.
        $unverified = array_filter(
            $rejected,
            fn (ZohoInvalidRecipientException $exception): bool => ! $exception->isVerifiedInvalidEmail(),
        );
        if ($unverified !== [] && $accepted === 0) {
            $codes = collect($unverified)
                ->map(fn (ZohoInvalidRecipientException $exception): string => $exception->zohoCode)
                ->unique()
                ->sort()
                ->values()
                ->implode(', ');

            throw new \RuntimeException(sprintf(
                'Zoho a rejete %d destinataire(s) avec un code non verifie (codes : %s) et aucun contact n\'a ete accepte pour cette vague.',
                count($unverified),
                $codes,
            ));
        }

        // Apply phase: verified codes get the existing permanent suppression;
        // unverified codes only skip the contact for this wave.
        foreach ($rejected as $email => $exception) {
            $handled = $exception->isVerifiedInvalidEmail()
                ? $this->suppressInvalidRecipient($run, $email)
                : $this->skipRecipientForWave($run, $email, $exception->zohoCode);

            if (! $handled) {
                throw $exception;
            }
        }

        if ($target === []) {
            return $this->finishEmpty($run, $listKey, $listName);
        }

        $after = $this->emailsByNormalizedValue($this->gateway->listEmails($listKey));
        if (array_diff_key($target, $after) !== []) {
            throw new \RuntimeException('Zoho ne confirme pas tous les destinataires de la vague.');
        }

        $run->forceFill([
            'status' => 'scheduled',
            'zoho_list_key' => $listKey,
            'driver_ref' => 'zoho-wave-synced',
            'failure_reason' => null,
        ])->save();
        return ['list_key' => $listKey, 'list_name' => $listName, 'contacts' => count($target)];
    }

    public function listName(CampaignRun $run): string
    {
        $run->loadMissing(['campaign', 'recipients']);
        preg_match('/^sequence-wave-(\d+)(?:-step-(\d+))?$/', $run->occurrence_key, $matches);
        $waveNumber = max(1, (int) ($matches[1] ?? 1));
        $suffix = ' - Wave ' . str_pad((string) $waveNumber, 3, '0', STR_PAD_LEFT);
        if (isset($matches[2])) {
            $suffix .= ' - Step ' . str_pad($matches[2], 3, '0', STR_PAD_LEFT);
        }
        $contactIds = $run->recipients->where('status', 'queued')->pluck('contact_id')->sort()->values()->implode(',');
        $suffix .= ' - A' . substr(sha1($contactIds), 0, 8);
        $campaignName = self::sanitizeCampaignName($run->campaign?->name, $run->campaign_id);

        return Str::limit($campaignName, 191 - strlen($suffix), '') . $suffix;
    }

    /**
     * Strip a campaign name down to [A-Za-z0-9 ] before sending it to Zoho.
     * Live-verified (2026-07-27): `&` is rejected by createCampaign with code
     * 7006 ("CampaignName cannot contain special characters"); an em dash (—)
     * is accepted. The charset here is kept deliberately narrow — it's the
     * empirically-proven-safe set, not a claim that every excluded character
     * (beyond `&`) is actually rejected by Zoho.
     */
    public static function sanitizeCampaignName(?string $name, int $campaignId): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ',
            preg_replace('/[^A-Za-z0-9]+/', ' ', Str::ascii((string) $name))));

        return $clean !== '' ? $clean : 'Campaign ' . $campaignId;
    }

    /** @param string[] $emails */
    private function emailsByNormalizedValue(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $normalized = mb_strtolower(trim((string) $email));
            if ($normalized !== '') {
                $result[$normalized] = $normalized;
            }
        }
        return $result;
    }

    private function suppressInvalidRecipient(CampaignRun $run, string $email): bool
    {
        return DB::transaction(function () use ($run, $email): bool {
            $recipient = CampaignRecipient::query()
                ->where('campaign_run_id', $run->id)
                ->where('status', 'queued')
                ->whereHas('contact', fn ($query) => $query->where('email', $email))
                ->with('contact')
                ->lockForUpdate()
                ->first();

            if ($recipient?->contact === null || $run->sequenceStep === null) {
                return false;
            }

            $enrollment = SequenceEnrollment::query()
                ->where('campaign_id', $run->campaign_id)
                ->where('contact_id', $recipient->contact_id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                return false;
            }

            $recipient->update(['status' => 'skipped', 'skip_reason' => 'invalid_email']);
            $enrollment->update([
                'status' => 'stopped',
                'stopped_reason' => 'invalid_email',
                'next_send_at' => null,
            ]);
            Suppression::firstOrCreate(
                ['email' => $email],
                ['contact_id' => $recipient->contact_id, 'reason' => 'invalid_email', 'source' => 'sequence'],
            );
            SequenceStepSend::updateOrCreate(
                ['enrollment_id' => $enrollment->id, 'step_no' => $run->sequenceStep->step_no],
                ['campaign_run_id' => $run->id, 'status' => 'skipped'],
            );

            return true;
        }, 3);
    }

    /**
     * Wave-only skip for an unverified rejection code: this contact is parked
     * out of the current run so the rest of the wave can complete, but nothing
     * permanent is written — no Suppression, enrollment stays active. Returns
     * false when the recipient can't be attributed (caller rethrows).
     */
    private function skipRecipientForWave(CampaignRun $run, string $email, string $zohoCode): bool
    {
        return DB::transaction(function () use ($run, $email, $zohoCode): bool {
            $recipient = CampaignRecipient::query()
                ->where('campaign_run_id', $run->id)
                ->where('status', 'queued')
                ->whereHas('contact', fn ($query) => $query->where('email', $email))
                ->with('contact')
                ->lockForUpdate()
                ->first();

            if ($recipient?->contact === null) {
                return false;
            }

            $recipient->update(['status' => 'skipped', 'skip_reason' => 'zoho_rejected']);

            if ($run->sequenceStep !== null) {
                $enrollment = SequenceEnrollment::query()
                    ->where('campaign_id', $run->campaign_id)
                    ->where('contact_id', $recipient->contact_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if ($enrollment !== null) {
                    SequenceStepSend::updateOrCreate(
                        ['enrollment_id' => $enrollment->id, 'step_no' => $run->sequenceStep->step_no],
                        ['campaign_run_id' => $run->id, 'status' => 'skipped'],
                    );
                }
            }

            Log::channel('campaign')->warning('[CampaignWaveZohoListSyncService] Contact Zoho rejete avec un code non verifie ; ignore pour cette vague uniquement.', [
                'run_id' => $run->id,
                'email' => $email,
                'zoho_code' => $zohoCode,
            ]);

            return true;
        }, 3);
    }

    /** @return array{list_key: string, list_name: string, contacts: int} */
    private function finishEmpty(CampaignRun $run, string $listKey, string $listName): array
    {
        Log::channel('campaign')->warning('[CampaignWaveZohoListSyncService] Wave has no eligible contacts left; marking sent as empty.', [
            'run_id' => $run->id,
            'list_key' => $listKey,
            'list_name' => $listName,
        ]);

        $run->update([
            'status' => 'sent',
            'stats_sent' => 0,
            'driver_ref' => 'zoho-wave-empty',
            'finished_at' => now(),
            'failure_reason' => null,
        ]);

        return ['list_key' => $listKey, 'list_name' => $listName, 'contacts' => 0];
    }
}
