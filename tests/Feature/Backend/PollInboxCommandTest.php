<?php

namespace Tests\Feature\Backend;

use App\Jobs\FetchInboxJob;
use App\Models\InboxEmail;
use App\Models\SenderIdentity;
use App\Services\Inbox\InboxImapService;
use App\Services\Inbox\ReplyMatchingService;
use Carbon\Carbon;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PollInboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dispatches_only_eligible_identities(): void
    {
        Queue::fake();
        $eligible = $this->identity();
        $this->identity(['email' => 'disabled@example.test', 'imap_enabled' => false]);
        $this->identity(['email' => 'inactive@example.test', 'is_active' => false]);
        $this->identity(['email' => 'missing-host@example.test', 'imap_host' => null]);

        $this->artisan('inbox:poll')->assertSuccessful();

        Queue::assertPushed(FetchInboxJob::class, 1);
        Queue::assertPushed(FetchInboxJob::class, fn (FetchInboxJob $job) => $job->senderIdentityId === $eligible->id);
    }

    public function test_job_has_unique_and_overlap_locks(): void
    {
        $job = new FetchInboxJob(123);
        $middleware = $job->middleware();

        $this->assertSame('123', $job->uniqueId());
        $this->assertSame(540, $job->timeout);
        $this->assertSame(900, $job->uniqueFor());
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('inbox-fetch-v2-123', $middleware[0]->key);
        $this->assertNull($middleware[0]->releaseAfter);
        $this->assertSame(600, $middleware[0]->expiresAfter);
    }

    public function test_success_resets_poll_health(): void
    {
        $identity = $this->identity([
            'consecutive_poll_failures' => 2,
            'last_poll_error' => 'old error',
        ]);

        (new FetchInboxJob($identity->id))->handle(
            new EmptyInboxImapService(),
            app(ReplyMatchingService::class),
        );

        $identity->refresh();
        $this->assertSame(0, $identity->consecutive_poll_failures);
        $this->assertNull($identity->last_poll_error);
        $this->assertNotNull($identity->last_polled_at);
        $this->assertTrue($identity->imap_enabled);
    }

    public function test_three_consecutive_failures_disable_polling_and_redact_credentials(): void
    {
        $identity = $this->identity([
            'imap_username' => 'private-user@example.test',
            'imap_password' => 'private-password',
        ]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            (new FetchInboxJob($identity->id))->handle(
                new FailingInboxImapService(),
                app(ReplyMatchingService::class),
            );
            $this->assertSame($attempt, $identity->fresh()->consecutive_poll_failures);
        }

        $identity->refresh();
        $this->assertFalse($identity->imap_enabled);
        $this->assertStringContainsString('[redacted]', $identity->last_poll_error);
        $this->assertStringNotContainsString('private-password', $identity->last_poll_error);
        $this->assertStringNotContainsString('private-user@example.test', $identity->last_poll_error);
    }

    public function test_failed_hook_uses_the_same_failure_bookkeeping(): void
    {
        $identity = $this->identity(['imap_password' => 'hook-password']);

        (new FetchInboxJob($identity->id))->failed(new RuntimeException('Failure hook-password'));

        $identity->refresh();
        $this->assertSame(1, $identity->consecutive_poll_failures);
        $this->assertStringNotContainsString('hook-password', $identity->last_poll_error);
    }

    public function test_matcher_failure_rolls_back_the_message_for_a_later_retry(): void
    {
        $identity = $this->identity();
        $job = new FetchInboxJob($identity->id);

        $job->handle(new OneMessageInboxImapService(), new ThrowingReplyMatchingService());
        $this->assertSame(0, InboxEmail::count());

        $this->assertSame(1, $identity->fresh()->consecutive_poll_failures);
        $this->assertNotNull($identity->fresh()->last_poll_error);
        $job->handle(new OneMessageInboxImapService(), app(ReplyMatchingService::class));
        $this->assertSame(1, InboxEmail::count());
        $this->assertSame(0, $identity->fresh()->consecutive_poll_failures);
        $this->assertNull($identity->fresh()->last_poll_error);
    }

    private function identity(array $attributes = []): SenderIdentity
    {
        static $counter = 0;
        $counter++;

        $identity = SenderIdentity::create(array_merge([
            'name' => 'Poll identity ' . $counter,
            'email' => 'poll-' . $counter . '@example.test',
            'is_active' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'poll-user@example.test',
            'imap_password' => 'poll-password',
            'imap_enabled' => true,
        ], array_intersect_key($attributes, array_flip([
            'name', 'email', 'is_active', 'imap_host', 'imap_username', 'imap_password', 'imap_enabled',
        ]))));

        $health = array_intersect_key($attributes, array_flip(['consecutive_poll_failures', 'last_poll_error']));
        if ($health !== []) {
            SenderIdentity::whereKey($identity->id)->update($health);
            $identity->refresh();
        }

        return $identity;
    }
}

class EmptyInboxImapService extends InboxImapService
{
    public function streamAll(SenderIdentity $identity, ?Carbon $since = null): Generator
    {
        if (false) {
            yield null;
        }
    }
}

class FailingInboxImapService extends InboxImapService
{
    public function streamAll(SenderIdentity $identity, ?Carbon $since = null): Generator
    {
        if (false) {
            yield null;
        }

        throw new RuntimeException('Login failed for ' . $identity->imap_username . ' with ' . $identity->imap_password);
    }
}

class OneMessageInboxImapService extends InboxImapService
{
    public function streamAll(SenderIdentity $identity, ?Carbon $since = null): Generator
    {
        yield (object) [];
    }

    public function storeMessage(SenderIdentity $identity, mixed $message): ?InboxEmail
    {
        return InboxEmail::create([
            'sender_identity_id' => $identity->id,
            'message_id' => 'transaction-retry@example.test',
            'from_email' => 'prospect@example.test',
            'status' => InboxEmail::STATUS_NOUVEAU,
        ]);
    }

    public function threadReferences(mixed $message): ?string
    {
        return null;
    }
}

class ThrowingReplyMatchingService extends ReplyMatchingService
{
    public function match(InboxEmail $email, ?string $references = null): void
    {
        throw new RuntimeException('Matcher failure');
    }
}
