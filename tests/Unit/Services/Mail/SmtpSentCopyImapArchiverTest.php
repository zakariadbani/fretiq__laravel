<?php

namespace Tests\Unit\Services\Mail;

use App\Models\SenderIdentity;
use App\Services\Mail\AmbiguousSentCopyAppendException;
use App\Services\Mail\SmtpSentCopyImapArchiver;
use Carbon\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Webklex\IMAP\Facades\Client as Imap;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\ImapServerErrorException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\MessageCollection;

class SmtpSentCopyImapArchiverTest extends TestCase
{
    private const ID = '<Mixed.Case@example.test>';

    private const MIME = "Message-ID: <Mixed.Case@example.test>\r\nSubject: saved\r\n\r\nExact body\r\n";

    #[DataProvider('archiveScenarios')]
    public function test_imap_outcomes_preserve_exact_copies_and_do_not_blindly_repeat_append(
        array $searchResults, ?string $appendError, bool $disconnectError, bool $reconnectError,
        int $expectedAppends, ?string $expectedException, int $runs = 1,
    ): void {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getConfig')->andReturn(Config::make(['options' => ['debug' => true]]));
        $folder = Mockery::mock(Folder::class);
        $connects = 0;
        $appends = [];
        $searches = 0;
        $client->shouldReceive('connect')->andReturnUsing(function () use (&$connects, $reconnectError, $client): Client {
            $this->assertFalse($client->getConfig()->get('options.debug'), 'IMAP protocol debugging could expose the MIME.');
            if (++$connects > 1 && $reconnectError) {
                throw new \RuntimeException('connection reset with private details');
            }

            return $client;
        });
        $client->shouldReceive('disconnect')->andReturnUsing(function () use ($disconnectError, $client): Client {
            if ($disconnectError) {
                throw new \RuntimeException('logout connection lost');
            }

            return $client;
        });
        $client->shouldReceive('getFolderByPath')->with('INBOX.Sent')->andReturn($folder);
        $folder->shouldReceive('messages')->andReturnUsing(function () use (&$searches, $searchResults): WhereQuery {
            $ids = $searchResults[$searches++] ?? [];
            $messages = array_map(function (string $id): Message {
                // Use the installed parser: its Message-ID attribute omits angle brackets.
                $header = new Header("Message-ID: {$id}\r\nSubject: existing\r\n", Config::make());
                $message = Mockery::mock(Message::class);
                $message->shouldReceive('getMessageId')->andReturn($header->get('message_id'));

                return $message;
            }, $ids);
            $query = Mockery::mock(WhereQuery::class);
            $query->shouldReceive('leaveUnread')->once()->andReturnSelf();
            $query->shouldReceive('setFetchBody')->with(false)->once()->andReturnSelf();
            $query->shouldReceive('setFetchFlags')->with(false)->once()->andReturnSelf();
            $query->shouldReceive('whereMessageId')->with('"'.self::ID.'"')->once()->andReturnSelf();
            $query->shouldReceive('get')->once()->andReturn(new MessageCollection($messages));

            return $query;
        });
        $folder->shouldReceive('appendMessage')->andReturnUsing(function ($mime, $flags, $date) use (&$appends, $appendError): array {
            $appends[] = [$mime, $flags, $date->format('c')];
            if ($appendError !== null) {
                if (str_starts_with($appendError, 'NO ') || str_starts_with($appendError, 'BYE ')) {
                    throw new ImapServerErrorException($appendError);
                }
                throw new \RuntimeException($appendError);
            }

            return ['OK'];
        });
        Imap::shouldReceive('make')->times($runs)->with(Mockery::on(fn (array $config) => $config['validate_cert'] === true
            && $config['host'] === 'imap.example.test' && $config['password'] === 'fixture-secret'))->andReturn($client);
        $identity = new SenderIdentity(['imap_host' => 'imap.example.test', 'imap_username' => 'fixture', 'imap_password' => 'fixture-secret', 'imap_validate_cert' => true]);
        $caught = null;
        try {
            for ($i = 0; $i < $runs; $i++) {
                (new SmtpSentCopyImapArchiver)->archive($identity, 'INBOX.Sent', self::ID, self::MIME, Carbon::parse('2026-09-09T10:15:00Z'));
            }
        } catch (\Throwable $exception) {
            $caught = $exception;
        }
        if ($expectedException === null) {
            $this->assertNull($caught, $caught?->getMessage() ?? '');
        } else {
            $this->assertInstanceOf($expectedException, $caught);
        }
        $this->assertCount($expectedAppends, $appends);
        foreach ($appends as $append) {
            $this->assertSame([self::MIME, ['\\Seen'], '2026-09-09T10:15:00+00:00'], $append);
        }
    }

    public static function archiveScenarios(): array
    {
        return [
            'new copy' => [[[]], null, false, false, 1, null],
            'duplicate invocation' => [[[], [self::ID]], null, false, false, 1, null, 2],
            'exact parsed ID already saved' => [[[self::ID]], null, false, false, 0, null],
            'substring is not the same ID' => [[['<prefixMixed.Case@example.test>']], null, false, false, 1, null],
            'logout failure after success' => [[[]], null, true, false, 1, null],
            'lost acknowledgement found' => [[[], [self::ID]], 'empty response', false, false, 1, null],
            'lost acknowledgement absent' => [[[], []], 'timeout', false, false, 1, AmbiguousSentCopyAppendException::class],
            'reconnect failure remains uncertain' => [[[]], 'connection reset', false, true, 1, AmbiguousSentCopyAppendException::class],
            'logout cannot erase uncertainty' => [[[], []], 'timeout', true, false, 1, AmbiguousSentCopyAppendException::class],
            'server BYE is uncertain' => [[[], []], 'BYE closing connection', false, false, 1, AmbiguousSentCopyAppendException::class],
            'explicit NO rejection is safely retryable' => [[[]], 'NO [OVERQUOTA] mailbox full', false, false, 1, ImapServerErrorException::class],
        ];
    }

    public function test_missing_configured_folder_never_appends_or_creates_a_folder(): void
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('getConfig')->andReturn(Config::make());
        $client->shouldReceive('connect')->once();
        $client->shouldReceive('disconnect')->once();
        $client->shouldReceive('getFolderByPath')->with('INBOX.Sent')->once()->andReturnNull();
        $client->shouldNotReceive('createFolder');
        Imap::shouldReceive('make')->andReturn($client);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configured Sent folder is unavailable.');
        (new SmtpSentCopyImapArchiver)->archive(new SenderIdentity(['imap_host' => 'imap.example.test', 'imap_username' => 'fixture', 'imap_password' => 'fixture-secret']), 'INBOX.Sent', self::ID, self::MIME, now());
    }

    public function test_missing_mailbox_credentials_do_not_attempt_a_default_connection(): void
    {
        Imap::shouldReceive('make')->never();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IMAP configuration is incomplete.');
        (new SmtpSentCopyImapArchiver)->archive(new SenderIdentity, 'INBOX.Sent', self::ID, self::MIME, now());
    }
}
