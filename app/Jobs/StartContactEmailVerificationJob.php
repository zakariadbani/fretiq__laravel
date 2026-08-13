<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Discovery\ContactVerificationService;
use App\Services\Discovery\EmailVerificationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class StartContactEmailVerificationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 120;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly int $contactId,
        public readonly string $emailHash,
    ) {
        $this->onQueue('prospecting');
    }

    public function uniqueId(): string
    {
        return $this->contactId.':'.$this->emailHash;
    }

    public function handle(
        ContactVerificationService $verification,
        EmailVerificationSettings $settings,
    ): void {
        if (! $settings->enabled()) {
            $this->release(300);

            return;
        }

        $contact = Contact::query()->find($this->contactId);
        if ($contact === null || hash('sha256', strtolower(trim((string) $contact->email))) !== $this->emailHash) {
            return;
        }

        if (filled($contact->email_verification_status)
            || filled($contact->email_verification_source)
            || $contact->email_verification_checked_at !== null) {
            return;
        }

        $verification->verify($contact);
    }
}
