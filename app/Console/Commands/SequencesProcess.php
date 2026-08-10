<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Campaign\SequenceService;
use App\Services\Campaign\SequenceWaveService;
use Illuminate\Console\Command;

/**
 * SequencesProcess — dispatch SendSequenceStepJob for all due enrollments.
 *
 * Runs every minute via the scheduler (routes/console.php) with withoutOverlapping().
 * Calls SequenceService::processDue() which only dispatches queue jobs; the actual
 * sends happen asynchronously in the worker.
 *
 * Signature: sequences:process
 */
class SequencesProcess extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'sequences:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatcher SendSequenceStepJob pour toutes les inscriptions de séquence dont l\'envoi est dû.';

    /**
     * Execute the command.
     */
    public function handle(SequenceWaveService $waveService): int
    {
        // No live Zoho driver means no gateway/list job should be recovered.
        if (config('services.zoho.driver', 'local') === 'zoho') {
            $waveService->recover();
        }
        $count = app(SequenceService::class)->processDue();

        $this->info("{$count} inscription(s) de séquence mise(s) en file d'attente.");

        return Command::SUCCESS;
    }
}
