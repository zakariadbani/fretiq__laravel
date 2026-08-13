<?php

namespace Tests\Unit\Services\Mail;

use Tests\TestCase;

class SmtpRoutingArchitectureTest extends TestCase
{
    public function test_application_mail_sends_converge_on_the_router(): void
    {
        $files = collect(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())))
            ->filter(fn (\SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
            ->map(fn (\SplFileInfo $file): string => $file->getPathname());

        $bypasses = $files->filter(function (string $file): bool {
            if (str_replace('\\', '/', $file) === str_replace('\\', '/', app_path('Services/Mail/SmtpMailRouter.php'))) {
                return false;
            }

            $source = collect(token_get_all((string) file_get_contents($file)))
                ->reject(fn ($token): bool => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($token): string => is_array($token) ? $token[1] : $token)
                ->implode('');

            return preg_match('/use\s+Illuminate\\\\Support\\\\Facades\\\\Mail\s*;/', $source) === 1
                || preg_match('/(?:\bMail|\\\\Illuminate\\\\Support\\\\Facades\\\\Mail)::(?:to|send|sendNow|raw|queue|later|mailer)\s*\(/', $source) === 1;
        });

        $this->assertSame([], $bypasses->values()->all());
    }
}
