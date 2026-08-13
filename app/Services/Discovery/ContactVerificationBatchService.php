<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Jobs\StartContactEmailVerificationJob;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

final class ContactVerificationBatchService
{
    /** @return array{eligible:int,estimated_cost:float,unit_cost:float,exclusions:array<string,int>} */
    public function estimate(): array
    {
        $eligible = $this->eligibleQuery()->count();
        $total = Contact::query()->count();
        $alreadyVerified = Contact::query()->where(function (Builder $query): void {
            $query->whereNotNull('email_verification_status')
                ->orWhereNotNull('email_verification_source')
                ->orWhereNotNull('email_verification_checked_at');
        })->count();
        $suppressed = Contact::query()
            ->whereNotNull('email')->whereRaw("TRIM(email) <> ''")
            ->whereExists(function ($query): void {
                $query->selectRaw('1')->from('suppressions')
                    ->whereRaw('LOWER(TRIM(suppressions.email)) = LOWER(TRIM(contacts.email))');
            })->count();
        $missingEmail = Contact::query()
            ->where(fn (Builder $query) => $query->whereNull('email')->orWhereRaw("TRIM(email) = ''"))
            ->count();
        $unitCost = (float) config('prospecting.provider_units.hunter.email_verifier', 0.5);

        return [
            'eligible' => $eligible,
            'unit_cost' => $unitCost,
            'estimated_cost' => round($eligible * $unitCost, 2),
            'exclusions' => [
                'total' => $total,
                'already_verified' => $alreadyVerified,
                'suppressed' => $suppressed,
                'missing_email' => $missingEmail,
            ],
        ];
    }

    public function enqueue(): int
    {
        $count = 0;
        $this->eligibleQuery()->select(['id', 'email'])->orderBy('id')->chunkById(250, function ($contacts) use (&$count): void {
            foreach ($contacts as $contact) {
                StartContactEmailVerificationJob::dispatch(
                    (int) $contact->id,
                    hash('sha256', strtolower(trim((string) $contact->email))),
                )->afterCommit();
                $count++;
            }
        });

        return $count;
    }

    private function eligibleQuery(): Builder
    {
        return Contact::query()
            ->whereNotNull('email')->whereRaw("TRIM(email) <> ''")
            ->whereNull('email_verification_status')
            ->whereNull('email_verification_source')
            ->whereNull('email_verification_checked_at')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('suppressions')
                    ->whereRaw('LOWER(TRIM(suppressions.email)) = LOWER(TRIM(contacts.email))');
            });
    }
}
