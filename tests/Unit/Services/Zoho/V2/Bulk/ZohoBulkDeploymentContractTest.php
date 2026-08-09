<?php

namespace Tests\Unit\Services\Zoho\V2\Bulk;

use Tests\TestCase;

class ZohoBulkDeploymentContractTest extends TestCase
{
    public function test_repository_documents_the_dedicated_dark_launch_worker_contract(): void
    {
        $command = 'php artisan queue:work zoho --queue=zoho --sleep=3 --tries=5 --timeout=900 --max-time=3600';
        $template = file_get_contents(base_path('deploy/supervisor/fretiq-zoho-worker.conf.example'));
        $readme = file_get_contents(base_path('README.md'));

        $this->assertIsString($template);
        $this->assertStringContainsString('command='.$command, $template);
        $this->assertStringContainsString('numprocs=1', $template);
        $this->assertStringContainsString('stopwaitsecs=1500', $template);
        $this->assertStringContainsString($command, $readme);
        $this->assertStringContainsString('ZOHO_V2_BULK_BACKFILL_ENABLED=false', $readme);
        $this->assertStringContainsString('supervisorctl status fretiq-worker-zoho:*', $readme);
        $this->assertStringContainsString('queue:monitor zoho:zoho --max=100', $readme);
    }
}
