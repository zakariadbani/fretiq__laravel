<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Models\CampaignRun;
use App\Services\Zoho\ZohoRecipientListGateway;
use Illuminate\Support\Str;

/** Mirrors one frozen paced-sequence wave into its own Zoho recipient list. */
class CampaignWaveZohoListSyncService
{
    public function __construct(private readonly ZohoRecipientListGateway $gateway) {}

    /** @return array{list_key: string, list_name: string, contacts: int} */
    public function sync(CampaignRun $run): array
    {
        $run->loadMissing(['campaign', 'recipients.contact.company']);
        if (! str_starts_with($run->occurrence_key, 'sequence-wave-')) {
            throw new \InvalidArgumentException('Cette execution n\'est pas une vague de sequence.');
        }

        $target = [];
        foreach ($run->recipients as $recipient) {
            $contact = $recipient->contact;
            $email = mb_strtolower(trim((string) $contact?->email));
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
            throw new \LogicException('La vague Zoho ne contient aucun destinataire avec une adresse e-mail.');
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
        foreach (array_chunk(array_values($toPush), 10) as $chunk) {
            $this->gateway->addContacts($listKey, $chunk);
        }

        $after = $this->emailsByNormalizedValue($this->gateway->listEmails($listKey));
        if (array_diff_key($target, $after) !== []) {
            throw new \RuntimeException('Zoho ne confirme pas tous les destinataires de la vague.');
        }

        $run->forceFill(['status' => 'prepared', 'zoho_list_key' => $listKey, 'driver_ref' => 'zoho-wave-synced'])->save();
        return ['list_key' => $listKey, 'list_name' => $listName, 'contacts' => count($target)];
    }

    public function listName(CampaignRun $run): string
    {
        $run->loadMissing('campaign');
        preg_match('/^sequence-wave-(\d+)$/', $run->occurrence_key, $matches);
        $waveNumber = max(1, (int) ($matches[1] ?? 1));
        $suffix = ' - Wave ' . str_pad((string) $waveNumber, 3, '0', STR_PAD_LEFT);
        $campaignName = trim((string) preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9]+/', ' ', Str::ascii((string) $run->campaign?->name))));
        $campaignName = $campaignName !== '' ? $campaignName : 'Campaign ' . $run->campaign_id;

        return Str::limit($campaignName, 191 - strlen($suffix), '') . $suffix;
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
}