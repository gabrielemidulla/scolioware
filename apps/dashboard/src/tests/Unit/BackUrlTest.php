<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\BackUrl;
use App\Http\RedirectValidator;
use PHPUnit\Framework\TestCase;

final class BackUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testValidateAcceptsAllowedScript(): void
    {
        self::assertSame('index.php', BackUrl::validate('index.php'));
        self::assertSame('patients.php?page=2', BackUrl::validate('patients.php?page=2'));
        self::assertSame(
            'patients.php?page=2',
            BackUrl::validate('patients.php?page=2&_bad=1&9start=2')
        );
    }

    public function testValidateRejectsExternal(): void
    {
        self::assertNull(BackUrl::validate('https://evil.example/'));
        self::assertNull(BackUrl::validate('//evil.example/foo'));
    }

    public function testRedirectValidatorUsesAllowList(): void
    {
        $allowed = ['index.php', 'patients.php'];
        self::assertSame('/index.php', RedirectValidator::safeRelativePath('/index.php', '/index.php', $allowed));
        self::assertSame('/index.php', RedirectValidator::safeRelativePath('/evil.php', '/index.php', $allowed));
    }
}
