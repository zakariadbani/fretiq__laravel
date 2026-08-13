<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ProviderCall;
use App\Services\Discovery\ContactVerificationService;
use App\Services\Discovery\EmailVerificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class VerifyContactEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public readonly int $contactId, public readonly string $idempotencyKey)
    {
        $this->onQueue('prospecting');
    }

    public function handle(ContactVerificationService $service, EmailVerificationSettings $settings): void
    {
        if (! $settings->enabled()) {
            $this->release(300);

            return;
        }

        $call = ProviderCall::query()
            ->where('provider', 'hunter')
            ->where('operation', 'email_verifier')
            ->where('idempotency_key', $this->idempotencyKey)
            ->first();
        if ($call === null || $call->status !== 'pending' || Contact::query()->find($this->contactId) === null) {
            return;
        }

        $service->poll($this->contactId, $this->idempotencyKey);
    }

    public function failed(Throwable $exception): void
    {
        // Fail closed without recording exception/provider payloads. The pending
        // state is intentionally left for an operator or retry policy to inspect.
    }
}
