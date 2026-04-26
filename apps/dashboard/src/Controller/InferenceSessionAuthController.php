<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** nginx auth_request for /inference/*. */
final class InferenceSessionAuthController
{
    #[Route('/inference_session_auth.php', name: 'internal_inference_session_auth', methods: ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])]
    public function __invoke(): Response
    {
        if (\PHP_SAPI === 'cli') {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Strict',
                'secure' => sw_session_cookie_secure(),
            ]);
            session_name('SW_SESSION');
            @session_start(['read_and_close' => true]);
        }

        $pid = isset($_SESSION['physician_id']) ? (int) $_SESSION['physician_id'] : 0;
        if ($pid <= 0) {
            return new Response('', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        try {
            $u = sw_find_physician($pid);
        } catch (\Throwable) {
            $u = null;
        }
        if (!$u || $u['deleted_at'] !== null
            || (isset($u['password_must_change']) && (int) $u['password_must_change'] === 1)) {
            return new Response('', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
