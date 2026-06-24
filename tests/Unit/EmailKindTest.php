<?php

namespace Tests\Unit;

use App\Support\EmailKind;
use Tests\TestCase;

/**
 * EmailKindTest — unit tests for App\Support\EmailKind::classify().
 *
 * Extends Tests\TestCase (not PHPUnit\Framework\TestCase) because classify()
 * calls config('prospecting.freemail_domains') which requires the Laravel app.
 */
class EmailKindTest extends TestCase
{
    public function test_corporate_named_email_is_role(): void
    {
        $this->assertSame('role', EmailKind::classify('p.grouillet@centrimex.com'));
    }

    public function test_gmail_is_personal(): void
    {
        $this->assertSame('personal', EmailKind::classify('jean@gmail.com'));
    }

    public function test_corporate_role_address_is_role(): void
    {
        $this->assertSame('role', EmailKind::classify('contact@centrimex.com'));
    }

    public function test_null_email_is_role(): void
    {
        $this->assertSame('role', EmailKind::classify(null));
    }

    public function test_orange_fr_is_personal(): void
    {
        $this->assertSame('personal', EmailKind::classify('someone@orange.fr'));
    }
}
