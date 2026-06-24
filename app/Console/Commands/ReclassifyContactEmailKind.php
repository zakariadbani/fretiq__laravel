<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Support\EmailKind;
use Illuminate\Console\Command;

/**
 * ReclassifyContactEmailKind — backfill email_kind using domain-based classification.
 *
 * Re-classifies every contact row by running App\Support\EmailKind::classify()
 * against the stored email address. The old Hunter-type-based logic stamped many
 * corporate named addresses (e.g. p.grouillet@centrimex.com) as 'personal'; this
 * command corrects those rows to 'role'.
 *
 * Safe to re-run: contacts already classified correctly are skipped (no-op update).
 *
 * Signature: contacts:reclassify-email-kind [--dry-run]
 */
class ReclassifyContactEmailKind extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'contacts:reclassify-email-kind
                            {--dry-run : Preview changes without writing to the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reclassifie email_kind de tous les contacts par domaine (corrige le bug Hunter type=personal).';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no writes');
        }

        $scanned = 0;
        $changed = 0;

        /** @var array<string, array<string, int>> $transitions e.g. ['personal' => ['role' => 5]] */
        $transitions = [];

        Contact::chunkById(500, function ($contacts) use ($dryRun, &$scanned, &$changed, &$transitions) {
            foreach ($contacts as $contact) {
                $scanned++;

                $new = EmailKind::classify($contact->email);

                if ($new === $contact->email_kind) {
                    continue;
                }

                $old = (string) $contact->email_kind;
                $transitions[$old][$new] = ($transitions[$old][$new] ?? 0) + 1;

                $changed++;

                if (! $dryRun) {
                    $contact->email_kind = $new;
                    $contact->save();
                }
            }
        });

        $this->info("Scanned : {$scanned} contact(s)");
        $this->info("Changed : {$changed} contact(s)");

        if ($transitions === []) {
            $this->info('All contacts already have the correct email_kind — nothing to update.');
        } else {
            $rows = [];
            foreach ($transitions as $old => $newMap) {
                foreach ($newMap as $new => $count) {
                    $rows[] = ["{$old} → {$new}", $count];
                }
            }
            $this->table(['Transition', 'Count'], $rows);
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no writes performed. Remove --dry-run to apply.');
        }

        return Command::SUCCESS;
    }
}
