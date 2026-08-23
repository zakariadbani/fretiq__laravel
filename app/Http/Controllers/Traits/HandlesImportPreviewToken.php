<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Encrypted preview→store token round-trip, shared by the strategy-import
 * controllers (segments, sequences, campaigns, campaign templates). Mirrors
 * App\Http\Controllers\Backend\ProspectCriteriaController's private
 * makeImportToken()/decryptImportToken() pair — four real callers need the
 * identical mechanic, so it lives here instead of being copied four times.
 * ProspectCriteriaController itself is untouched (out of scope).
 */
trait HandlesImportPreviewToken
{
    /**
     * @param  list<array<string, mixed>>  $rows
     *
     * 'importer' binds the token to the controller that minted it (static::class,
     * late-static-bound to the concrete controller via the trait) — without it, a
     * token minted by one strategy-import controller (e.g. SegmentController) is
     * accepted verbatim by another's store() (e.g. CampaignController), which then
     * reads its own row shape off rows built for a different importer.
     */
    protected function makeImportToken(int $userId, array $rows): string
    {
        return Crypt::encryptString(json_encode([
            'schema' => 1,
            'importer' => static::class,
            'user_id' => $userId,
            'issued_at' => now()->timestamp,
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<array<string, mixed>> */
    protected function decryptImportToken(string $token, int $userId, int $maxRows, string $errorKey = 'csv'): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$errorKey => ['La prévisualisation est invalide ou a expiré. Importez de nouveau le fichier.']]);
        }

        $issuedAt = $payload['issued_at'] ?? null;
        $rows = $payload['rows'] ?? null;
        if (($payload['schema'] ?? null) !== 1
            || ($payload['importer'] ?? null) !== static::class
            || ($payload['user_id'] ?? null) !== $userId
            || ! is_int($issuedAt)
            || ! is_array($rows)
            || $rows === []
            || count($rows) > $maxRows
            || $issuedAt < now()->subMinutes(30)->timestamp
            || $issuedAt > now()->addMinute()->timestamp) {
            throw ValidationException::withMessages([$errorKey => ['La prévisualisation est invalide ou a expiré. Importez de nouveau le fichier.']]);
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['name']) || trim((string) $row['name']) === '') {
                throw ValidationException::withMessages([$errorKey => ['La prévisualisation est invalide ou a expiré. Importez de nouveau le fichier.']]);
            }
        }

        return $rows;
    }
}
