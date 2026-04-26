<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Locale\SwLocale;
use PHPUnit\Framework\TestCase;

final class AuthSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        putenv('SW_HIBP_PASSWORD_CHECK');
        parent::tearDown();
    }

    public function testSwCsrfCheckToken(): void
    {
        sw_session_start();
        $_SESSION['csrf_token'] = 'deadbeefdeadbeefdeadbeefdeadbeef';
        self::assertTrue(sw_csrf_check_token('deadbeefdeadbeefdeadbeefdeadbeef'));
        self::assertFalse(sw_csrf_check_token('wrong'));
        self::assertFalse(sw_csrf_check_token(''));
    }

    public function testSwValidatePasswordLength(): void
    {
        putenv('SW_HIBP_PASSWORD_CHECK=0');
        SwLocale::init();
        self::assertNotNull(sw_validate_password('short'));
        self::assertNull(sw_validate_password('long_enough_pass'));
    }
}
