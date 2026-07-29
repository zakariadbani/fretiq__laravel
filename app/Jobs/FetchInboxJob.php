<?php

namespace App\Jobs;

use App\Models\SenderIdentity;
use App\Models\Setting;
use App\Services\Inbox\InboxImapService;
use App\Services\Inbox\ReplyMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchInboxJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 540;

    public function __construct(public readonly int $senderIdentityId) {}

    public function uniqueId(): string
    {
        return (string) $this->senderIdentityId;
    }

    public function uniqueFor(): int
    {
        return 900;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('inbox-fetch-v2-' . $this->senderIdentityId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(InboxImapService $imap, ReplyMatchingService $matcher): void
    {
        $identity = SenderIdentity::find($this->senderIdentityId);
        if ($identity === null
            || ! $identity->imap_enabled
            || ! $identity->is_active
            || blank($identity->imap_host)
            || blank($identity->imap_username)) {
            return;
        }

        try {
            $days = Setting::get('inbox.refresh_days', 7);
            $days = is_numeric($days) ? max(1, min(90, (int) $days)) : 7;
            $messageFailure = null;

            foreach ($imap->streamAll($identity, now()->subDays($days)) as $message) {
                try {
                    DB::transaction(function () use ($identity, $message, $imap, $matcher): void {
                        $email = $imap->storeMessage($identity, $message);
                        if ($email !== null) {
                            $matcher->match($email, $imap->threadReferences($message));
                        }
                    });
                } catch (Throwable $exception) {
                    $messageFailure = $exception;
                    Log::warning('Inbox message match failed; message will be retried.', [
                        'sender_identity_id' => $identity->id,
                        'exception_class' => $exception::class,
                    ]);
                }
            }

            if ($messageFailure !== null) {
                throw $messageFailure;
            }

            SenderIdentity::whereKey($identity->id)->update([
                'last_polled_at' => now(),
                'last_poll_error' => null,
                'consecutive_poll_failures' => 0,
            ]);
        } catch (Throwable $exception) {
            $this->recordFailure($identity, $exception, $imap);
        }
    }

    public function failed(Throwable $exception): void
    {
        $identity = SenderIdentity::find($this->senderIdentityId);
        if ($identity !== null) {
            $this->recordFailure($identity, $exception, app(InboxImapService::class));
        }
    }

    private function recordFailure(SenderIdentity $identity, Throwable $exception, InboxImapService $imap): void
    {
        $fresh = $identity->fresh() ?? $identity;
        $failures = min(255, (int) $fresh->consecutive_poll_failures + 1);
        $error = mb_substr($imap->redact($identity, $exception->getMessage()), 0, 500);

        SenderIdentity::whereKey($identity->id)->update([
            'last_poll_error' => $error !== '' ? $error : 'Échec de la relève IMAP.',
            'consecutive_poll_failures' => $failures,
            'imap_enabled' => $failures >= 3 ? false : $fresh->imap_enabled,
        ]);

        Log::warning('Inbox polling failed.', [
            'sender_identity_id' => $identity->id,
            'consecutive_failures' => $failures,
            'imap_disabled' => $failures >= 3,
            'exception_class' => $exception::class,
        ]);
    }
}
