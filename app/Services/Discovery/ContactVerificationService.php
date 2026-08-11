<?php

namespace App\Services\Discovery;

use App\Jobs\VerifyContactEmailJob;
use App\Models\Contact;
use App\Models\Suppression;
use App\Services\Providers\Hunter\HunterClient;
use App\Services\Providers\ProviderCallContext;
use App\Services\Providers\ProviderCallLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ContactVerificationService
{
    private const TERMINAL_STATUSES = ['valid', 'invalid', 'accept_all', 'webmail', 'disposable', 'unknown'];

    /** @var list<int> */
    private const POLL_DELAYS_MINUTES = [1, 15, 60, 360, 720];

    public function __construct(
        private readonly HunterClient $hunter,
        private readonly ProviderCallLedger $ledger,
    ) {}

    public function verify(Contact $contact, bool $force = false, ?string $clientToken = null): Contact
    {
        if ($this->isFresh($contact) && ! $force) {
            return $contact->fresh() ?? $contact;
        }

        if ($force && (! is_string($clientToken) || ! Str::isUuid($clientToken))) {
            throw new InvalidArgumentException('contact_verification_force_token_invalid');
        }

        $key = $this->idempotencyKey($contact, $force, $clientToken);

        return $this->execute($contact->id, $key, false);
    }

    public function poll(int $contactId, string $idempotencyKey): Contact
    {
        if (preg_match('/^[a-f0-9]{64}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('contact_verification_key_invalid');
        }

        return $this->execute($contactId, $idempotencyKey, true);
    }

    public function approveManually(Contact $contact): Contact
    {
        return DB::transaction(function () use ($contact): Contact {
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);
            $locked->forceFill([
                'email_verification_status' => 'unknown',
                'email_verification_source' => 'manual',
                'email_verification_checked_at' => now(),
            ])->save();

            return $locked->fresh();
        });
    }

    private function execute(int $contactId, string $key, bool $isPoll): Contact
    {
        $contact = Contact::query()->findOrFail($contactId);
        $execution = $this->hunter->emailVerifier($this->context($key), strtolower(trim((string) $contact->email)));

        if ($execution->replayed) {
            return $contact->fresh() ?? $contact;
        }

        $response = $execution->response;
        if ($response === null) {
            throw new \LogicException('contact_verification_response_missing');
        }

        if ($response->httpStatus === 202) {
            // The ledger deliberately bounds a logical provider call to four
            // transports. The final pending response is therefore converted to
            // a fresh, safe unknown result while the reservation is still
            // settleable, rather than leaving the contact pending forever.
            if ($isPoll && $execution->call->attempt_count >= 4) {
                return $this->settleUnknown($contactId, $execution);
            }

            return DB::transaction(function () use ($contactId, $execution): Contact {
                $locked = Contact::query()->lockForUpdate()->findOrFail($contactId);
                $delayIndex = max(0, min(count(self::POLL_DELAYS_MINUTES) - 1, $execution->call->attempt_count - 1));
                $retryAt = now()->addMinutes(self::POLL_DELAYS_MINUTES[$delayIndex]);
                $this->ledger->markPending($execution, $retryAt, ['status' => 'pending']);
                $locked->forceFill([
                    'email_verification_status' => 'pending',
                    'email_verification_source' => 'hunter',
                    'email_verification_checked_at' => null,
                ])->save();

                VerifyContactEmailJob::dispatch($locked->id, $execution->call->idempotency_key)
                    ->delay($retryAt)
                    ->afterCommit();

                return $locked->fresh();
            });
        }

        return DB::transaction(function () use ($contactId, $execution): Contact {
            $locked = Contact::query()->lockForUpdate()->findOrFail($contactId);
            $response = $execution->response;
            $providerStatus = (string) ($response?->meta['provider_status'] ?? '');
            $claimed = $providerStatus === '451' || ($response?->meta['error_code'] ?? null) === 'claimed_email';
            $status = strtolower(trim((string) ($response?->data['status'] ?? 'unknown')));
            if ($providerStatus === '222' || ! in_array($status, self::TERMINAL_STATUSES, true)) {
                $status = 'unknown';
            }
            if ($claimed) {
                $status = 'invalid';
            }

            $locked->forceFill([
                'email_verification_status' => $status,
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
            ])->save();

            if ($claimed) {
                Suppression::query()->firstOrCreate(
                    ['email' => strtolower(trim((string) $locked->email))],
                    ['contact_id' => $locked->id, 'reason' => 'claimed', 'source' => 'hunter'],
                );
            }

            $this->ledger->settle($execution, 1, $this->consumedUnits($execution), [
                'status' => $status,
                'reason' => $claimed ? 'claimed' : null,
            ]);

            return $locked->fresh();
        });
    }

    private function settleUnknown(int $contactId, \App\Services\Providers\ProviderExecution $execution): Contact
    {
        return DB::transaction(function () use ($contactId, $execution): Contact {
            $locked = Contact::query()->lockForUpdate()->findOrFail($contactId);
            $locked->forceFill([
                'email_verification_status' => 'unknown',
                'email_verification_source' => 'hunter',
                'email_verification_checked_at' => now(),
            ])->save();
            $this->ledger->settle($execution, 1, $this->consumedUnits($execution), ['status' => 'unknown', 'reason' => 'poll_exhausted']);

            return $locked->fresh();
        });
    }

    private function context(string $key): ProviderCallContext
    {
        return new ProviderCallContext($key, $this->unitCost(), engine: 'email_verifier');
    }

    private function unitCost(): float
    {
        return (float) config('prospecting.provider_units.hunter.email_verifier', 0.5);
    }

    private function consumedUnits(\App\Services\Providers\ProviderExecution $execution): float
    {
        return (float) $execution->call->reserved_units > 0 ? $this->unitCost() : 0.0;
    }

    private function isFresh(Contact $contact): bool
    {
        return $contact->email_verification_checked_at?->gte(
            now()->subDays((int) config('prospecting.email_verification_ttl_days', 90)),
        ) ?? false;
    }

    private function idempotencyKey(Contact $contact, bool $force, ?string $clientToken): string
    {
        $generation = $force
            ? 'force:'.$clientToken
            : ($contact->email_verification_checked_at?->format('Uu') ?? 'never');

        return hash('sha256', sprintf(
            'contact-email-verifier:v1:%d:%s:%s',
            $contact->id,
            hash('sha256', strtolower(trim((string) $contact->email))),
            $generation,
        ));
    }
}
