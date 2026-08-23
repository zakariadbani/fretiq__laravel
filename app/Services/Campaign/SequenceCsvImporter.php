<?php

namespace App\Services\Campaign;

use App\Models\CampaignRun;
use App\Models\CampaignTemplate;
use App\Models\Sequence;
use App\Models\SequenceStep;
use Illuminate\Support\Facades\DB;

/**
 * CSV columns: name;step_no;delay_days;template_name;subject
 *
 * One row = one step. Multiple rows sharing the same sequence name build
 * that sequence's ordered steps — dedupKey() is overridden to key on
 * (name, step_no) instead of name alone, since repeating a sequence name is
 * the whole point here.
 */
class SequenceCsvImporter extends AbstractCsvImporter
{
    public const HEADERS = ['name', 'step_no', 'delay_days', 'template_name', 'subject'];

    public function headers(): array
    {
        return self::HEADERS;
    }

    protected function dedupKey(array $row): ?string
    {
        return mb_strtolower((string) $row['name']).'|'.$row['step_no'];
    }

    protected function normalizeRow(array $raw, int $rowNumber, array &$errors): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $errors[] = "Ligne {$rowNumber} : le nom de la séquence est requis.";

            return null;
        }

        $stepNo = $this->number($raw['step_no'] ?? null);
        if ($stepNo === null || $stepNo < 1) {
            $errors[] = "Ligne {$rowNumber} : step_no doit être un entier positif.";

            return null;
        }

        $delayDays = $this->number($raw['delay_days'] ?? null, 0);
        if ($delayDays === null || $delayDays < 0) {
            $errors[] = "Ligne {$rowNumber} : delay_days doit être un entier positif ou nul.";

            return null;
        }

        $templateName = trim((string) ($raw['template_name'] ?? ''));
        $template = $templateName !== ''
            ? CampaignTemplate::whereRaw('LOWER(name) = ?', [mb_strtolower($templateName)])->first()
            : null;
        if ($templateName === '' || $template === null) {
            $errors[] = "Ligne {$rowNumber} : le modèle « {$templateName} » est introuvable.";

            return null;
        }

        return [
            'name' => $name,
            'step_no' => $stepNo,
            'delay_days' => $delayDays,
            'template_id' => $template->id,
            'template_name' => $template->name,
            'subject' => $this->nullableText($raw['subject'] ?? null),
        ];
    }

    /**
     * Group the flat, per-line validated steps returned by parse() into one
     * preview item per sequence name, steps ordered by step_no.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{name: string, will_update: bool, steps: list<array<string, mixed>>}>
     */
    public function groupIntoSequences(array $steps): array
    {
        $groups = [];
        foreach ($steps as $step) {
            $key = mb_strtolower($step['name']);
            $groups[$key]['name'] ??= $step['name'];
            $groups[$key]['steps'][] = $step;
        }

        return array_values(array_map(function (array $group): array {
            usort($group['steps'], fn ($a, $b) => $a['step_no'] <=> $b['step_no']);

            return [
                'name' => $group['name'],
                'will_update' => Sequence::whereRaw('LOWER(name) = ?', [mb_strtolower($group['name'])])->exists(),
                'steps' => $group['steps'],
            ];
        }, $groups));
    }

    /**
     * Upsert each sequence by case-insensitive name and upsert its steps by
     * (sequence_id, step_no) — existing step rows are updated in place
     * (never delete-then-recreate) so `campaign_runs.sequence_step_id`
     * keeps pointing at a live row for every step_no the re-import still
     * has. Existing sequences keep their current is_active; new ones are
     * created inactive.
     *
     * Steps whose step_no drops out of the new file ("surplus") are only
     * deleted when the sequence is not live (no active enrollment and no
     * non-terminal campaign_run tied to one of its steps) — deleting a
     * step a live enrollment or an in-flight wave still points at would
     * strand that run's `sequence_step_id` and let
     * SequenceWaveService::adoptLegacyEnrollments() re-adopt it into a
     * competing duplicate run. When surplus is kept, a warning is
     * returned instead so the operator knows steps were not removed.
     *
     * @param  list<array<string, mixed>>  $steps  flat rows, as returned by parse()
     * @return array{created: int, updated: int, steps: int, warnings: list<string>}
     */
    public function store(array $steps): array
    {
        return DB::transaction(function () use ($steps): array {
            $created = 0;
            $updated = 0;
            $stepCount = 0;
            $warnings = [];

            foreach ($this->groupIntoSequences($steps) as $group) {
                $sequence = Sequence::whereRaw('LOWER(name) = ?', [mb_strtolower($group['name'])])->first();
                if ($sequence === null) {
                    $sequence = Sequence::create(['name' => $group['name'], 'is_active' => false]);
                    $created++;
                } else {
                    $updated++;
                }

                $existingSteps = $sequence->steps()->get()->keyBy('step_no');
                $incomingStepNos = array_column($group['steps'], 'step_no');

                foreach ($group['steps'] as $step) {
                    $existing = $existingSteps->get($step['step_no']);
                    if ($existing !== null) {
                        $existing->update([
                            'delay_days' => $step['delay_days'],
                            'template_id' => $step['template_id'],
                            'subject' => $step['subject'],
                        ]);
                    } else {
                        SequenceStep::create([
                            'sequence_id' => $sequence->id,
                            'step_no' => $step['step_no'],
                            'delay_days' => $step['delay_days'],
                            'template_id' => $step['template_id'],
                            'subject' => $step['subject'],
                        ]);
                    }
                    $stepCount++;
                }

                $surplus = $existingSteps->reject(
                    fn (SequenceStep $existingStep): bool => in_array($existingStep->step_no, $incomingStepNos, true)
                );
                if ($surplus->isNotEmpty()) {
                    if ($this->sequenceIsLive($sequence)) {
                        $warnings[] = "« {$sequence->name} » : séquence en cours — étapes excédentaires conservées.";
                    } else {
                        SequenceStep::whereIn('id', $surplus->pluck('id'))->delete();
                    }
                }
            }

            return ['created' => $created, 'updated' => $updated, 'steps' => $stepCount, 'warnings' => $warnings];
        });
    }

    /**
     * A sequence is "live" when removing one of its steps could strand an
     * in-flight send: an active enrollment may reference any step by
     * current_step, and a non-terminal campaign_run may reference one via
     * sequence_step_id.
     */
    private function sequenceIsLive(Sequence $sequence): bool
    {
        if ($sequence->enrollments()->whereIn('status', ['active', 'paused'])->exists()) {
            return true;
        }

        return CampaignRun::whereIn('status', ['prepared', 'scheduled', 'sending'])
            ->whereHas('sequenceStep', fn ($query) => $query->where('sequence_id', $sequence->id))
            ->exists();
    }
}
