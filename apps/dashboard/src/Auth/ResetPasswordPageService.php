<?php

declare(strict_types=1);

namespace App\Auth;

use Symfony\Component\HttpFoundation\Request;

final class ResetPasswordPageService
{
    public function resolveToken(Request $request): string
    {
        $t = trim((string) $request->query->get('token', ''));
        if ($t === '' && $request->isMethod('POST')) {
            $t = trim((string) $request->request->get('token', ''));
        }

        return $t;
    }

    /** @return array<string, mixed>|null */
    public function lookupToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        return sw_lookup_reset_token($token);
    }

    /** @param array<string, mixed> $row */
    public function handlePasswordPost(Request $request, array $row): array
    {
        $new1 = (string) $request->request->get('new_password', '');
        $new2 = (string) $request->request->get('new_password_confirm', '');
        if ($new1 !== $new2) {
            return ['error' => (string) __('reset.error_mismatch'), 'success' => false];
        }
        $pwErr = sw_validate_password($new1);
        if ($pwErr !== null) {
            return ['error' => $pwErr, 'success' => false];
        }
        sw_set_password((int) $row['physician_id'], $new1);
        sw_mark_reset_token_used((int) $row['id']);

        return ['error' => '', 'success' => true];
    }
}
