<?php

namespace App\Services\Mail;

use App\Models\SenderIdentity;
use Carbon\Carbon;
use RuntimeException;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;

class SmtpSentCopyImapArchiver
{
    public function archive(SenderIdentity $identity, string $folderName, string $messageId, string $mime, \DateTimeInterface $sentAt): void
    {
        if (blank($identity->imap_host) || blank($identity->imap_username) || blank($identity->imap_password)) {
            throw new RuntimeException('IMAP configuration is incomplete.');
        }
        $client = Client::make($this->config($identity));
        $client->getConfig()->set('options.debug', false);
        try {
            $client->connect();
            $folder = $client->getFolderByPath($folderName);
            if ($folder === null) {
                throw new RuntimeException('Configured Sent folder is unavailable.');
            }
            if ($this->exists($folder, $messageId)) {
                return;
            }
            try {
                $folder->appendMessage($mime, ['\\Seen'], Carbon::instance($sentAt));
            } catch (\Throwable $exception) {
                // Only a tagged NO/BAD proves the server rejected this APPEND.
                // BYE, EOF and all other errors may follow a successful write.
                if ($exception instanceof ImapServerErrorException
                    && preg_match('/^(?:NO|BAD)(?:\s|$)/i', $exception->getMessage()) === 1) {
                    throw $exception;
                }
                try {
                    $this->disconnectQuietly($client);
                    $client->connect();
                    $reconnectedFolder = $client->getFolderByPath($folderName);
                    if ($reconnectedFolder !== null && $this->exists($reconnectedFolder, $messageId)) {
                        return;
                    }
                } catch (\Throwable) {
                }
                throw new AmbiguousSentCopyAppendException('SMTP Sent copy append outcome is uncertain.');
            }
        } finally {
            $this->disconnectQuietly($client);
        }
    }

    private function exists(mixed $folder, string $messageId): bool
    {
        if (preg_match('/[\r\n\0]/', $messageId) === 1) {
            throw new RuntimeException('Invalid Message-ID.');
        }

        $searchValue = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $messageId).'"';
        $messages = $folder->messages()->leaveUnread()->setFetchBody(false)->setFetchFlags(false)
            ->whereMessageId($searchValue)->get();
        foreach ($messages as $message) {
            if ($this->normalizeMessageId((string) $message->getMessageId()) === $this->normalizeMessageId($messageId)) {
                return true;
            }
        }

        return false;
    }

    private function config(SenderIdentity $identity): array
    {
        return ['host' => $identity->imap_host, 'port' => $identity->imap_port ?? 993,
            'encryption' => ($identity->imap_encryption ?? 'ssl') === 'none' ? false : $identity->imap_encryption,
            'validate_cert' => (bool) ($identity->imap_validate_cert ?? true), 'username' => $identity->imap_username,
            'password' => $identity->imap_password, 'protocol' => 'imap'];
    }

    private function normalizeMessageId(string $messageId): string
    {
        $messageId = trim($messageId);

        return str_starts_with($messageId, '<') && str_ends_with($messageId, '>')
            ? substr($messageId, 1, -1) : $messageId;
    }

    private function disconnectQuietly(ImapClient $client): void
    {
        try {
            $client->disconnect();
        } catch (\Throwable) {
            // Cleanup must not erase an acknowledged or uncertain APPEND outcome.
        }
    }
}
