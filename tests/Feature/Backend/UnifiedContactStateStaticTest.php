<?php

namespace Tests\Feature\Backend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UnifiedContactStateStaticTest extends TestCase
{
    public function test_active_application_code_no_longer_depends_on_removed_contact_fields_or_reply_switch(): void
    {
        $roots = [
            app_path(),
            config_path(),
            base_path('routes'),
            resource_path('views'),
            database_path('factories'),
            database_path('seeders'),
        ];
        $forbidden = [
            'contact_statuses',
            'contact_legal_bases',
            'legal_basis',
            'consent_at',
            'stop_on_reply',
            'approveManually',
            'approve-email',
            'filter.status',
        ];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! in_array($file->getExtension(), ['php', 'js', 'ts'], true)) {
                    continue;
                }

                $contents = File::get($file->getPathname());
                foreach ($forbidden as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $contents,
                        sprintf('Removed dependency "%s" remains in %s.', $needle, $file->getPathname()),
                    );
                }
            }
        }
    }
}
