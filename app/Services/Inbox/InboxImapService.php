<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Models\InboxEmail;
use App\Models\SenderIdentity;
use Carbon\Carbon;
use Webklex\IMAP\Facades\Client;

class InboxImapService
{
    public function testConnection(SenderIdentity $identity): void
    {
        $config = $this->buildImapConfig($identity);
        $config['timeout'] = 10;
        $client = Client::make($config);
        $client->connect();

        try {
            $client->getFolder('INBOX');
        } finally {
            $client->disconnect();
        }
    }

    /** @return \Generator<mixed> */
    public function streamAll(SenderIdentity $identity, ?Carbon $since = null): \Generator
    {
        $client = Client::make($this->buildImapConfig($identity));
        $client->connect();

        try {
            $folder = $client->getFolder('INBOX');
            $messages = $since === null
                ? $folder->messages()->all()->get()
                : $folder->messages()->since($since)->get();

            foreach ($messages as $message) {
                yield $message;
            }
        } finally {
            $client->disconnect();
        }
    }

    public function storeMessage(SenderIdentity $identity, mixed $message): ?InboxEmail
    {
        $from = $message->getFrom()[0] ?? null;
        $to = $message->getTo()[0] ?? null;
        $fromEmail = mb_strtolower(trim((string) ($from?->mail ?? '')));
        $subject = trim((string) ($message->getSubject() ?? ''));
        $date = trim((string) ($message->getDate() ?? ''));

        if ($fromEmail === '' || strcasecmp($fromEmail, $identity->email) === 0) {
            return null;
        }

        $html = (string) ($message->getHTMLBody() ?? '');
        $cleanHtml = $this->sanitizeHtml($html);
        $text = (string) ($message->getTextBody() ?? '');
        $bodyText = $this->normalizeText($text !== '' ? $text : strip_tags($cleanHtml));
        $messageId = $this->normalizeMessageId(
            trim((string) ($message->getMessageId() ?? '')),
            $identity,
            $fromEmail,
            $subject,
            $date,
            hash('sha256', $text . "\0" . $html),
        );

        $email = InboxEmail::firstOrCreate(
            ['message_id' => $messageId],
            [
                'sender_identity_id' => $identity->id,
                'in_reply_to' => $this->limit($this->attribute($message, 'in_reply_to'), 500),
                'from_email' => $this->limit($fromEmail, 191),
                'from_name' => $this->nullableLimit((string) ($from?->personal ?? ''), 255),
                'to_email' => $this->nullableLimit(mb_strtolower((string) ($to?->mail ?? '')), 191),
                'subject' => $this->nullableLimit($subject, 500),
                'body_text' => $bodyText !== '' ? $bodyText : null,
                'body_html' => $cleanHtml !== '' ? $cleanHtml : null,
                'status' => InboxEmail::STATUS_NOUVEAU,
                'received_at' => $date !== '' ? Carbon::parse($date) : null,
            ],
        );

        return $email->wasRecentlyCreated ? $email : null;
    }

    public function threadReferences(mixed $message): ?string
    {
        return $this->nullableLimit($this->attribute($message, 'references'), 4000);
    }

    public function redact(SenderIdentity $identity, string $message): string
    {
        try {
            $password = (string) ($identity->imap_password ?? '');
        } catch (\Throwable) {
            $password = '';
        }

        $credentials = array_values(array_filter([
            $password,
            (string) ($identity->imap_username ?? ''),
        ], fn (string $value): bool => $value !== ''));

        return $credentials === [] ? $message : str_replace($credentials, '[redacted]', $message);
    }

    private function buildImapConfig(SenderIdentity $identity): array
    {
        return [
            'host' => $identity->imap_host,
            'port' => $identity->imap_port ?? 993,
            'encryption' => ($identity->imap_encryption ?? 'ssl') === 'none' ? false : $identity->imap_encryption,
            'validate_cert' => (bool) ($identity->imap_validate_cert ?? true),
            'username' => $identity->imap_username,
            'password' => $identity->imap_password,
            'protocol' => 'imap',
        ];
    }

    private function normalizeMessageId(
        string $messageId,
        SenderIdentity $identity,
        string $fromEmail,
        string $subject,
        string $date,
        string $contentHash,
    ): string {
        $messageId = trim($messageId, " <>\t\n\r\0\x0B");

        if ($messageId === '') {
            return 'sha1:' . sha1(implode('|', [$identity->id, $fromEmail, $subject, $date, $contentHash]));
        }

        if (strlen($messageId) > 191 || preg_match('/[^\x20-\x7E]/', $messageId) === 1) {
            return 'sha1:' . sha1($messageId);
        }

        return $messageId;
    }

    private function attribute(mixed $message, string $name): string
    {
        try {
            return trim((string) ($message->{$name} ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function sanitizeHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $cachePath = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,i,u,strong,em,a[href],ul,ol,li,table,tr,td,th,thead,tbody,h1,h2,h3,h4,h5,h6,span,div,img[src|alt|width|height],blockquote,pre,code,hr');
        $config->set('HTML.Nofollow', true);
        $config->set('URI.DisableExternalResources', true);
        $config->set('URI.DisableResources', true);
        $config->set('HTML.SafeIframe', false);
        $config->set('HTML.SafeObject', false);
        $config->set('Cache.SerializerPath', $cachePath);

        return trim((new \HTMLPurifier($config))->purify($html));
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode(trim(strip_tags($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function limit(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    private function nullableLimit(string $value, int $length): ?string
    {
        return $this->limit($value, $length);
    }
}
