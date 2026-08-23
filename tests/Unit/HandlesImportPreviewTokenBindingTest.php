<?php

namespace Tests\Unit;

use App\Http\Controllers\Traits\HandlesImportPreviewToken;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit tests for HandlesImportPreviewToken — the encrypted preview→store
 * token round-trip shared by the strategy-import controllers (segments,
 * sequences, campaigns, campaign templates).
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because Crypt
 * needs the Laravel app booted. No DB is touched.
 *
 * Without the 'importer' discriminator, a token minted by one controller's
 * importPreview() is accepted verbatim by a *different* controller's
 * import_store — the second controller then reads its own row shape off
 * rows built for a different importer (e.g. CampaignCsvImporter::store()
 * reading $row['segment_id'] off a segment-shaped row), an unhandled 500
 * on a user-reachable POST.
 */
class HandlesImportPreviewTokenBindingTest extends TestCase
{
    public function test_a_token_is_accepted_by_the_controller_that_minted_it(): void
    {
        $minter = new HandlesImportPreviewTokenFixtureA();
        $token = $minter->mint(1, [['name' => 'Row']]);

        $rows = $minter->decrypt($token, 1, 10);

        $this->assertSame([['name' => 'Row']], $rows);
    }

    public function test_a_token_minted_by_one_controller_is_rejected_by_another(): void
    {
        $minter = new HandlesImportPreviewTokenFixtureA();
        $token = $minter->mint(1, [['name' => 'Row']]);
        $reader = new HandlesImportPreviewTokenFixtureB();

        try {
            $reader->decrypt($token, 1, 10);
            $this->fail('Expected a validation exception for a cross-controller token.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv', $exception->errors());
        }
    }
}

class HandlesImportPreviewTokenFixtureA
{
    use HandlesImportPreviewToken;

    /** @param  list<array<string, mixed>>  $rows */
    public function mint(int $userId, array $rows): string
    {
        return $this->makeImportToken($userId, $rows);
    }

    /** @return list<array<string, mixed>> */
    public function decrypt(string $token, int $userId, int $maxRows): array
    {
        return $this->decryptImportToken($token, $userId, $maxRows);
    }
}

class HandlesImportPreviewTokenFixtureB
{
    use HandlesImportPreviewToken;

    /** @return list<array<string, mixed>> */
    public function decrypt(string $token, int $userId, int $maxRows): array
    {
        return $this->decryptImportToken($token, $userId, $maxRows);
    }
}
