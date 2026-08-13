<?php

namespace App\Console\Commands;

use App\Services\Prospecting\BlockedProspectItemRecoveryService;
use Illuminate\Console\Command;

final class RecoverBlockedProspectItems extends Command
{
    protected $signature = 'prospecting:recover-blocked-items {--batch= : Lot requis} {--apply : Relancer les éléments éligibles} {--limit=25 : Maximum 100} {--spacing=5 : Espacement en secondes}';

    protected $description = 'Prévisualise ou relance de façon bornée les entreprises bloquées par une limite fournisseur.';

    public function handle(BlockedProspectItemRecoveryService $recovery): int
    {
        $batchId = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            $this->error('L’option --batch est requise.');

            return self::INVALID;
        }

        $limit = min(100, max(1, (int) $this->option('limit')));
        $ids = $recovery->eligibleIds($batchId, $limit);
        $this->line('Éléments éligibles : '.count($ids));
        if (! $this->option('apply')) {
            $this->warn('DRY RUN — aucune écriture, aucun job envoyé.');

            return self::SUCCESS;
        }

        $count = $recovery->apply($batchId, $limit, min(60, max(1, (int) $this->option('spacing'))));
        $this->info('Éléments relancés : '.$count);

        return self::SUCCESS;
    }
}
