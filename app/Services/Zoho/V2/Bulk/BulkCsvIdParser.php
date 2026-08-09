<?php

namespace App\Services\Zoho\V2\Bulk;

use Generator;
use RuntimeException;
use ZipArchive;

/** Validates a Zoho Bulk Read archive and yields record IDs without buffering the CSV. */
class BulkCsvIdParser
{
    /**
     * @return Generator<int, list<string>>
     */
    public function idChunks(string $archivePath, int $chunkSize): Generator
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is unavailable.');
        }

        $chunkSize = max(1, min(10_000, $chunkSize));
        $this->assertArchiveSize($archivePath);

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Bulk Read archive is invalid.');
        }

        $stream = null;

        try {
            if ($zip->numFiles !== 1) {
                throw new RuntimeException('Bulk Read archive must contain exactly one CSV.');
            }

            $stat = $zip->statIndex(0);
            $name = is_array($stat) ? ($stat['name'] ?? null) : null;
            if (! is_string($name) || preg_match('#^[^/\\\\]+\.csv$#i', $name) !== 1) {
                throw new RuntimeException('Bulk Read archive must contain exactly one CSV.');
            }

            $this->assertEntrySizes($stat);
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException('Cannot read Bulk Read CSV.');
            }

            $header = $this->readCsvRow($stream);
            if (! is_array($header) || ! in_array('Id', $header, true)) {
                throw new RuntimeException('Bulk Read CSV schema drift: Id column is required.');
            }

            $idIndex = array_search('Id', $header, true);
            $rowCount = 0;
            $chunk = [];
            $rowLimit = $this->positiveConfig('max_records_per_export', 200_000);

            while (($row = $this->readCsvRow($stream)) !== false) {
                if (++$rowCount > $rowLimit) {
                    throw new RuntimeException('Bulk Read CSV exceeded the configured row limit.');
                }

                $id = trim((string) ($row[$idIndex] ?? ''));
                if ($id === '' || preg_match('/^[0-9A-Za-z_-]{1,100}$/', $id) !== 1) {
                    throw new RuntimeException('Bulk Read CSV contained an invalid record identifier.');
                }

                $chunk[] = $id;
                if (count($chunk) === $chunkSize) {
                    yield $chunk;
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                yield $chunk;
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $zip->close();
        }
    }

    private function assertArchiveSize(string $path): void
    {
        $size = is_file($path) ? filesize($path) : false;
        if (! is_int($size) || $size < 1) {
            throw new RuntimeException('Bulk Read archive is unavailable.');
        }

        if ($size > $this->positiveConfig('max_archive_bytes', 64 * 1024 * 1024)) {
            throw new RuntimeException('Bulk Read archive exceeded the configured archive limit.');
        }
    }

    /** @param array<string,mixed>|false $stat */
    private function assertEntrySizes(array|false $stat): void
    {
        if (! is_array($stat)) {
            throw new RuntimeException('Bulk Read archive metadata is unavailable.');
        }

        $compressed = is_numeric($stat['comp_size'] ?? null) ? (int) $stat['comp_size'] : -1;
        $uncompressed = is_numeric($stat['size'] ?? null) ? (int) $stat['size'] : -1;
        if ($compressed < 0 || $uncompressed < 0) {
            throw new RuntimeException('Bulk Read archive metadata is invalid.');
        }

        if ($compressed > $this->positiveConfig('max_compressed_entry_bytes', 64 * 1024 * 1024)) {
            throw new RuntimeException('Bulk Read archive exceeded the configured compressed-file limit.');
        }

        if ($uncompressed > $this->positiveConfig('max_uncompressed_bytes', 512 * 1024 * 1024)) {
            throw new RuntimeException('Bulk Read archive exceeded the configured uncompressed-file limit.');
        }
    }

    private function positiveConfig(string $key, int $default): int
    {
        $value = config('zoho-v2.bulk.'.$key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    /** @param resource $stream @return list<string|null>|false */
    private function readCsvRow(mixed $stream): array|false
    {
        $limit = min(1_048_576, $this->positiveConfig('max_csv_line_bytes', 4_096));
        // The requested Bulk export contains only Id and Created_Time, so
        // embedded multiline CRM fields are not part of this CSV contract.
        $line = fgets($stream, $limit + 3);
        if ($line === false) {
            return false;
        }

        $terminated = str_ends_with($line, "\n") || feof($stream);
        $line = rtrim($line, "\r\n");
        if (! $terminated || strlen($line) > $limit) {
            throw new RuntimeException('Bulk Read CSV exceeded the configured line limit.');
        }

        return str_getcsv($line);
    }
}
