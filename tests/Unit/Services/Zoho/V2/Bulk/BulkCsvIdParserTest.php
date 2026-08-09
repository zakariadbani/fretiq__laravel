<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use App\Services\Zoho\V2\Bulk\BulkCsvIdParser;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BulkCsvIdParserTest extends TestCase
{
    public function test_it_streams_ids_from_a_single_bulk_csv_in_bounded_chunks(): void
    {
        $path = $this->zip("Id,Created_Time\nabc-1,2026-01-01\nabc-1,2026-01-01\ndef_2,2026-01-02\n");

        try {
            $chunks = iterator_to_array((new BulkCsvIdParser)->idChunks($path, 2), false);
        } finally {
            @unlink($path);
        }

        $this->assertSame([['abc-1', 'abc-1'], ['def_2']], $chunks);
    }

    public function test_it_rejects_schema_drift_without_an_id_header(): void
    {
        $path = $this->zip("Created_Time\n2026-01-01\n");

        try {
            $this->expectException(RuntimeException::class);
            iterator_to_array((new BulkCsvIdParser)->idChunks($path, 100), false);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_archives_with_more_than_one_file(): void
    {
        $path = $this->zip("Id\nabc-1\n", ['unexpected.csv' => "Id\ndef-2\n"]);

        try {
            $this->expectException(RuntimeException::class);
            iterator_to_array((new BulkCsvIdParser)->idChunks($path, 100), false);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_enforces_archive_uncompressed_and_row_caps(): void
    {
        $path = $this->zip("Id\nabc-1\ndef-2\n");
        config()->set('zoho-v2.bulk.max_records_per_export', 1);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('row limit');
            iterator_to_array((new BulkCsvIdParser)->idChunks($path, 100), false);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_an_archive_file_above_the_configured_cap(): void
    {
        $path = $this->zip("Id\nabc-1\n");
        config()->set('zoho-v2.bulk.max_archive_bytes', 1);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('archive limit');
            iterator_to_array((new BulkCsvIdParser)->idChunks($path, 100), false);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_rejects_a_csv_line_before_it_can_exceed_the_streaming_line_bound(): void
    {
        $path = $this->zip("Id\n12345678901234567890\n");
        config()->set('zoho-v2.bulk.max_csv_line_bytes', 16);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('line limit');
            iterator_to_array((new BulkCsvIdParser)->idChunks($path, 100), false);
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string,string> $extraFiles */
    private function zip(string $csv, array $extraFiles = []): string
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZIP extension unavailable.');
        }

        $path = tempnam(sys_get_temp_dir(), 'zoho-test-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('records.csv', $csv);
        foreach ($extraFiles as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
