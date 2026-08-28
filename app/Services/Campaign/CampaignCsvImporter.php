<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Segment;
use App\Models\SenderIdentity;
use App\Models\Sequence;
use App\Services\Discovery\EmailVerificationSettings;
use Illuminate\Support\Facades\DB;

/**
 * CSV columns: name;segment_name;sequence_name;template_name;schedule_type;
 * delivery_channel;email_verification_policy;smtp_daily_email_limit;is_active;notes
 *
 * Ref-requiredness mirrors Campaign::rules(): segment always required,
 * sequence required only when schedule_type=sequence, template required
 * otherwise. Hand-rolled rather than reusing Campaign::rules() directly —
 * that method reads $this->is_active / $this->schedule_type /
 * $this->sequence_enrollment_mode off a live model instance and is written
 * for the edit form's conditionals, not a raw future row; forcing a
 * transient model through it would fight those conditionals more than it'd
 * save.
 *
 * House rules beyond the model's own validation:
 *  - delivery_channel must be explicit smtp|zoho|mailjet (the model allows
 *    null). mailjet additionally rejects schedule_type=sequence — the
 *    Mailjet channel does not support sequences in v1.
 *  - is_active is forced to false only when CREATING a new campaign; updating
 *    an existing one never touches is_active (imports must never activate a
 *    send, but must not deactivate an already-running one either). A truthy
 *    CSV value only produces a preview warning, never an error, either way.
 *  - sender_identity_id is not a CSV column: resolved once per parse() call
 *    to the single existing SenderIdentity, or the whole file is rejected.
 */
class CampaignCsvImporter extends AbstractCsvImporter
{
    public const HEADERS = [
        'name', 'segment_name', 'sequence_name', 'template_name', 'schedule_type',
        'delivery_channel', 'email_verification_policy', 'smtp_daily_email_limit', 'is_active', 'notes',
    ];

    private ?int $senderIdentityId = null;

    public function headers(): array
    {
        return self::HEADERS;
    }

    public function parse(string $contents, string $filename): array
    {
        $count = SenderIdentity::query()->count();
        if ($count !== 1) {
            $this->fail("Aucune identité d'expéditeur unique disponible — définissez une identité d'expéditeur avant d'importer, ou importez puis assignez-la manuellement à chaque campagne.");
        }
        $this->senderIdentityId = (int) SenderIdentity::query()->value('id');

        return parent::parse($contents, $filename);
    }

    protected function normalizeRow(array $raw, int $rowNumber, array &$errors): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $errors[] = "Ligne {$rowNumber} : le nom est requis.";

            return null;
        }

        $segmentName = trim((string) ($raw['segment_name'] ?? ''));
        $segment = $segmentName !== ''
            ? Segment::whereRaw('LOWER(name) = ?', [mb_strtolower($segmentName)])->first()
            : null;
        if ($segmentName === '' || $segment === null) {
            $errors[] = "Ligne {$rowNumber} : le segment « {$segmentName} » est introuvable.";

            return null;
        }

        $scheduleTypes = array_keys(config('global.data.schedule_types', []));
        $scheduleType = trim((string) ($raw['schedule_type'] ?? ''));
        $scheduleType = $scheduleType === '' ? 'one_shot' : $scheduleType;
        if (! in_array($scheduleType, $scheduleTypes, true)) {
            $errors[] = "Ligne {$rowNumber} : schedule_type « {$scheduleType} » invalide (attendu : ".implode(', ', $scheduleTypes).').';

            return null;
        }

        $sequenceName = trim((string) ($raw['sequence_name'] ?? ''));
        $sequence = null;
        if ($sequenceName !== '') {
            $sequence = Sequence::whereRaw('LOWER(name) = ?', [mb_strtolower($sequenceName)])->first();
            if ($sequence === null) {
                $errors[] = "Ligne {$rowNumber} : la séquence « {$sequenceName} » est introuvable.";

                return null;
            }
        } elseif ($scheduleType === 'sequence') {
            $errors[] = "Ligne {$rowNumber} : sequence_name est requis lorsque schedule_type = sequence.";

            return null;
        }

        $templateName = trim((string) ($raw['template_name'] ?? ''));
        $template = null;
        if ($templateName !== '') {
            $template = CampaignTemplate::whereRaw('LOWER(name) = ?', [mb_strtolower($templateName)])->first();
            if ($template === null) {
                $errors[] = "Ligne {$rowNumber} : le modèle « {$templateName} » est introuvable.";

                return null;
            }
        } elseif ($scheduleType !== 'sequence') {
            $errors[] = "Ligne {$rowNumber} : template_name est requis (sauf en mode séquence).";

            return null;
        }

        $deliveryChannel = trim((string) ($raw['delivery_channel'] ?? ''));
        if (! in_array($deliveryChannel, ['smtp', 'zoho', 'mailjet'], true)) {
            $errors[] = "Ligne {$rowNumber} : delivery_channel doit être explicite (smtp, zoho ou mailjet).";

            return null;
        }
        if ($deliveryChannel === 'mailjet' && $scheduleType === 'sequence') {
            $errors[] = "Ligne {$rowNumber} : le canal Mailjet ne prend pas encore en charge les séquences.";

            return null;
        }

        $policy = trim((string) ($raw['email_verification_policy'] ?? ''));
        if ($policy === '') {
            $policy = app(EmailVerificationSettings::class)->defaultCampaignPolicy();
        } elseif (! in_array($policy, Campaign::EMAIL_VERIFICATION_POLICIES, true)) {
            $errors[] = "Ligne {$rowNumber} : email_verification_policy invalide (verified_only ou all_sendable).";

            return null;
        }

        $limitRaw = trim((string) ($raw['smtp_daily_email_limit'] ?? ''));
        $limit = null;
        if ($limitRaw !== '') {
            if (! preg_match('/^\d+$/', $limitRaw) || (int) $limitRaw > 500) {
                $errors[] = "Ligne {$rowNumber} : smtp_daily_email_limit doit être un entier entre 0 et 500.";

                return null;
            }
            $limit = (int) $limitRaw;
        }

        $warnings = [];
        $wantedActive = in_array(strtolower(trim((string) ($raw['is_active'] ?? ''))), ['1', 'true', 'oui', 'on', 'yes'], true);
        if ($wantedActive) {
            $warnings[] = 'is_active forcé à 0 — les imports ne créent ou ne mettent à jour jamais une campagne active.';
        }

        return [
            'name' => $name,
            'segment_id' => $segment->id,
            'segment_name' => $segment->name,
            'sequence_id' => $sequence?->id,
            'sequence_name' => $sequence?->name,
            'template_id' => $template?->id,
            'template_name' => $template?->name,
            'schedule_type' => $scheduleType,
            'delivery_channel' => $deliveryChannel,
            'email_verification_policy' => $policy,
            'smtp_daily_email_limit' => $limit,
            'sender_identity_id' => $this->senderIdentityId,
            'notes' => $this->nullableText($raw['notes'] ?? null),
            'warnings' => $warnings,
            'will_update' => Campaign::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(),
        ];
    }

    /**
     * Upsert by case-insensitive name. is_active is always forced to false.
     *
     * ponytail: trusts the ref ids baked into $rows at preview time rather
     * than re-querying segment/sequence/template/sender existence here —
     * same trust boundary ProspectCriteriaCsvImporter's token round-trip
     * already relies on. A ref deleted in the few-second gap between preview
     * and store surfaces as a DB FK error, not a friendly message; add a
     * re-validation pass here if that ever proves to matter in practice.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int}
     */
    public function store(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $existing = Campaign::whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->first();

                $attrs = [
                    'segment_id' => $row['segment_id'],
                    'template_id' => $row['template_id'],
                    'sequence_id' => $row['sequence_id'],
                    'sender_identity_id' => $row['sender_identity_id'],
                    'schedule_type' => $row['schedule_type'],
                    'delivery_channel' => $row['delivery_channel'],
                    'email_verification_policy' => $row['email_verification_policy'],
                ];

                // Blank cell on an existing campaign preserves its current
                // throttle instead of clearing it — mirrors
                // SegmentCsvImporter::store()'s "blank in the file keeps the
                // existing value" pattern for filter_json.
                if ($row['smtp_daily_email_limit'] !== null || $existing === null) {
                    $attrs['smtp_daily_email_limit'] = $row['smtp_daily_email_limit'];
                }

                if ($existing !== null) {
                    // Never touch is_active on update — an import must never
                    // activate a send, but it must not deactivate an
                    // already-running campaign either.
                    $existing->fill($attrs)->save();
                    $updated++;
                } else {
                    $attrs['is_active'] = false;
                    Campaign::create($attrs + ['name' => $row['name']]);
                    $created++;
                }
            }

            return ['created' => $created, 'updated' => $updated];
        });
    }
}
