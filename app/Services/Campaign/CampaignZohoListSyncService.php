<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Services\Zoho\ZohoRecipientListGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Prepares an immutable campaign-run audience snapshot and additively prepares
 * its dedicated campaign-owned Zoho list. This service never creates or sends a
 * Zoho campaign and never removes a Zoho list member.
 */
class CampaignZohoListSyncService
{
    public function __construct(
        private readonly SegmentService $segmentService,
        private readonly ZohoRecipientListGateway $gateway,
    ) {}

    /**
     * @return array{status: string, verified: bool, run: CampaignRun, added: int}
     */
    public function sync(Campaign $campaign): array
    {
        $contacts = $this->segmentService->resolve($campaign->segment, $campaign->emailVerificationPolicy());
        if ($campaign->emailVerificationPolicy() === Campaign::VERIFICATION_ALL_SENDABLE
            && $contacts->contains(fn ($contact): bool => $contact->email_verification_status === 'pending')) {
            throw new \LogicException('La préparation de liste attend la fin des vérifications email en cours. Aucun appel externe n’a été effectué.');
        }
        $target = $this->contactsByEmail($contacts);
        if ($target === []) {
            throw new \LogicException('La préparation de liste Zoho nécessite au moins un destinataire approuvé. Aucune liste, campagne ou envoi Zoho n’a été créé.');
        }
        $listKey = $this->ensurePersistedCampaignListKey($campaign, array_values($target));

        // Read membership before persisting the run snapshot. The default gateway
        // throws without HTTP until list create/read/add semantics are verified.
        $before = $this->emailsByNormalizedValue($this->gateway->listEmails($listKey));

        $run = $this->createAudienceSnapshot($campaign, $contacts, $listKey);
        $missing = array_diff_key($target, $before);

        // When a Zoho topic ("rubrique") is configured, delivery requires every
        // contact to be individually subscribed to that topic (see
        // ZohoCampaignsClient::addListSubscribers). Seed contacts pushed by
        // ensureCampaignList()/addlistandcontacts at list creation are already
        // list members but were never topic-subscribed, so they would be silently
        // skipped by Zoho's send if we only pushed the membership diff. Push the
        // full target audience through addContacts() in that case; 'added' below
        // still reports the membership diff, not the topic-subscribe count.
        $topicId = trim((string) config('services.zoho.campaigns.topic_id'));
        $toPush = $topicId !== '' ? $target : $missing;

        foreach (array_chunk(array_values($toPush), 10) as $chunk) {
            $this->gateway->addContacts($listKey, $chunk);
        }

        $after = $this->emailsByNormalizedValue($this->gateway->listEmails($listKey));
        $notAdded = array_diff_key($target, $after);
        if ($notAdded !== []) {
            throw new \RuntimeException('La vérification Zoho ne permet pas de confirmer que tous les destinataires préparés ont été ajoutés à la liste dédiée. Aucun envoi n’a été créé.');
        }

        return [
            'status' => 'synchronized',
            'verified' => true,
            'run' => $run,
            'added' => count($missing),
        ];
    }

    /** @param array<int, array<string, string>> $seedContacts */
    private function ensurePersistedCampaignListKey(Campaign $campaign, array $seedContacts): string
    {
        $existingKey = trim((string) $campaign->zoho_list_key);
        if ($existingKey !== '') {
            return $existingKey;
        }

        // The gateway must return Zoho's actual stable key. It is intentionally
        // unavailable by default; do not persist anything if it throws.
        $listKey = trim($this->gateway->ensureCampaignList($campaign->id, $this->campaignListName($campaign), $seedContacts));
        if ($listKey === '') {
            throw new \LogicException('La préparation de liste Zoho est bloquée : la liste dédiée ne fournit aucune clé stable.');
        }

        $campaign->forceFill(['zoho_list_key' => $listKey])->save();

        return $listKey;
    }

    private function campaignListName(Campaign $campaign): string
    {
        $campaignName = trim((string) preg_replace('/\s+/', ' ', $campaign->name));
        $rawName = 'Fretiq Campaign ' . $campaign->id . ' ' . $campaignName;
        $asciiName = Str::ascii($rawName);
        $safeName = trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $asciiName));

        return Str::limit($safeName, 191, '');
    }

    private function createAudienceSnapshot(Campaign $campaign, Collection $contacts, string $listKey): CampaignRun
    {
        $run = CampaignRun::create([
            'campaign_id' => $campaign->id,
            'occurrence_key' => 'zoho-list-' . now()->format('YmdHisv') . '-' . Str::lower(Str::random(6)),
            'run_at' => now(),
            'status' => 'prepared',
            'zoho_list_key' => $listKey,
            'driver_ref' => 'zoho-list-sync',
        ]);

        foreach ($contacts as $contact) {
            CampaignRecipient::firstOrCreate([
                'campaign_run_id' => $run->id,
                'contact_id' => $contact->id,
            ], ['status' => 'queued']);
        }

        return $run;
    }

    /** @return array<string, array<string, string>> */
    private function contactsByEmail(Collection $contacts): array
    {
        $result = [];
        foreach ($contacts as $contact) {
            $email = $this->normalizeEmail((string) $contact->email);
            if ($email === '') {
                continue;
            }
            $result[$email] = [
                'Contact Email' => $email,
                'First Name' => (string) ($contact->name ?? ''),
                'Company' => (string) ($contact->company?->name ?? ''),
            ];
        }
        ksort($result);

        return $result;
    }

    /** @param string[] $emails  @return array<string, string> */
    private function emailsByNormalizedValue(array $emails): array
    {
        $result = [];
        foreach ($emails as $email) {
            $normalized = $this->normalizeEmail((string) $email);
            if ($normalized !== '') {
                $result[$normalized] = $normalized;
            }
        }
        ksort($result);

        return $result;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
