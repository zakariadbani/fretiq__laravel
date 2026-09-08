<?php

namespace App\Jobs;

use App\Models\SenderIdentity;
use App\Services\Mail\AmbiguousSentCopyAppendException;
use App\Services\Mail\SmtpSentCopyImapArchiver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ArchiveSmtpSentCopyJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600];

    public int $timeout = 540;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $senderIdentityId,
        public readonly string $messageId,
        public readonly string $mime,
        public readonly \DateTimeImmutable $sentAt,
        public readonly string $folder,
    ) {
        $this->onConnection('database');
    }

    public function handle(SmtpSentCopyImapArchiver $archiver): void
    {
        $lock = null;
        $acquired = false;
        try {
            $identity = SenderIdentity::query()->whereKey($this->senderIdentityId)->first();
            if ($identity === null) {
                $this->warn(['message_id' => $this->messageId, 'reason' => 'identity_unavailable']);

                return;
            }
            $lock = Cache::lock('smtp-sent-copy:'.$identity->id.':'.hash('sha256', $this->messageId), 600);
            $acquired = $lock->get();
            if (! $acquired) {
                throw new \RuntimeException('SMTP Sent copy archive is busy.');
            }
            $archiver->archive($identity, $this->folder, $this->messageId, $this->mime, $this->sentAt);
        } catch (AmbiguousSentCopyAppendException) {
            $this->warn(['message_id' => $this->messageId, 'sender_identity_id' => $identity->id, 'reason' => 'append_uncertain']);
            $this->fail(new \RuntimeException('SMTP Sent copy append outcome is uncertain.'));

            return;
        } catch (\Throwable $exception) {
            $this->warn(['message_id' => $this->messageId, 'sender_identity_id' => isset($identity) ? $identity->id : null, 'reason' => 'archive_failure']);
            throw new \RuntimeException('SMTP Sent copy archive failed.');
        } finally {
            if ($acquired) {
                try {
                    $lock?->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function warn(array $context): void
    {
        try {
            Log::warning('SMTP Sent copy archive failed.', $context);
        } catch (\Throwable) {
        }
    }
}
