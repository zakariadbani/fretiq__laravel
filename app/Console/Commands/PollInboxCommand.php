<?php

namespace App\Console\Commands;

use App\Jobs\FetchInboxJob;
use App\Models\SenderIdentity;
use Illuminate\Console\Command;

class PollInboxCommand extends Command
{
    protected $signature = 'inbox:poll';
    protected $description = 'Dispatch IMAP inbox polling for enabled sender identities.';

    public function handle(): int
    {
        $ids = SenderIdentity::query()
            ->where('imap_enabled', true)
            ->where('is_active', true)
            ->whereNotNull('imap_host')
            ->whereNotNull('imap_username')
            ->pluck('id');

        foreach ($ids as $id) {
            FetchInboxJob::dispatch((int) $id);
        }

        $this->info("Dispatched {$ids->count()} inbox poll job(s).");

        return self::SUCCESS;
    }
}
