<?php

declare(strict_types=1);

namespace App\Auth;

final class CsrfProtector
{
    public static function verifyToken(string $token): bool
    {
        sw_session_start();

        return $token !== ''
            && !empty($_SESSION['csrf_token'])
            && hash_equals((string) $_SESSION['csrf_token'], $token);
    }
}
