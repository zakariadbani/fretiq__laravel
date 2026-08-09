<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use App\Services\Zoho\V2\Transport\BulkDownloadGuard;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Tests\TestCase;

class BulkDownloadGuardTest extends TestCase
{
    public function test_content_length_above_the_cap_is_rejected_before_streaming(): void
    {
        $guard = new BulkDownloadGuard(100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archive limit');
        $guard->assertHeaders(new Response(200, ['Content-Length' => '101']));
    }

    public function test_streaming_progress_above_the_cap_is_rejected_mid_download(): void
    {
        $guard = new BulkDownloadGuard(100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('archive limit');
        $guard->assertProgress(0, 101, 0, 0);
    }
}
