<?php

namespace App\Services\Mail;

use App\Jobs\ArchiveSmtpSentCopyJob;
use App\Models\SenderIdentity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Message;

class SmtpSentCopyDispatcher
{
    public function dispatchAfterAccepted(SentMessage $sent, ?int $senderIdentityId): void
    {
        if (! config('mail.smtp_sent_copy.enabled', false)) {
            return;
        }

        $mime = $sent->toString();
        $messageId = $this->messageIdFromMime($mime);
        if ($messageId === null) {
            Log::warning('SMTP Sent copy was not queued.', ['reason' => 'message_id_unavailable']);

            return;
        }

        $identity = $senderIdentityId === null
            ? $this->identityForFrom($sent)
            : SenderIdentity::query()->whereKey($senderIdentityId)->first();

        if ($identity === null || ! $this->hasMailbox($identity)) {
            Log::warning('SMTP Sent copy was not queued.', ['message_id' => $messageId, 'reason' => 'mailbox_unavailable']);

            return;
        }

        $job = (new ArchiveSmtpSentCopyJob(
            (int) $identity->id,
            $messageId,
            $mime,
            now('UTC')->toImmutable(),
            (string) config('mail.smtp_sent_copy.folder', 'INBOX.Sent'),
        ))->onConnection('database')->onQueue('default');
        Queue::connection('database')->push($job, '', 'default');
    }

    private function identityForFrom(SentMessage $sent): ?SenderIdentity
    {
        $original = $sent->getOriginalMessage();
        $from = $original instanceof Message ? $original->getHeaders()->get('From') : null;
        $addresses = $from?->getAddresses() ?? [];
        if (count($addresses) !== 1) {
            Log::warning('SMTP Sent copy was not queued.', ['reason' => 'from_unavailable']);

            return null;
        }

        $matches = SenderIdentity::query()->where('is_active', true)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($addresses[0]->getAddress())])->limit(2)->get();
        if ($matches->count() !== 1) {
            Log::warning('SMTP Sent copy was not queued.', ['reason' => 'identity_ambiguous']);

            return null;
        }

        return $matches->first();
    }

    private function hasMailbox(SenderIdentity $identity): bool
    {
        return filled($identity->imap_host) && filled($identity->imap_username) && filled($identity->imap_password);
    }

    private function messageIdFromMime(string $mime): ?string
    {
        $headers = preg_split("/\r?\n\r?\n/", $mime, 2)[0] ?? '';
        if (preg_match('/^Message-ID:\s*([^\r\n]+(?:\r?\n[ \t]+[^\r\n]+)*)/mi', $headers, $matches) !== 1) {
            return null;
        }

        $messageId = trim(preg_replace('/\r?\n[ \t]+/', '', $matches[1]) ?? '');

        return $messageId !== '' && preg_match('/[\r\n\0]/', $messageId) !== 1 ? $messageId : null;
    }
}
