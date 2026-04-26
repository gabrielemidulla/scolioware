<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Locale\SwLocale;
use App\Http\BackUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LoginController extends AbstractController
{
    #[Route('/login.php', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        if (sw_current_user() !== null) {
            return $this->redirect('index.php');
        }

        $error = '';
        $username = '';
        $ip = trim((string) ($request->getClientIp() ?? '0.0.0.0'));

        if ($request->isMethod('POST')) {
            sw_csrf_check();
            $username = trim((string) $request->request->get('username', ''));
            $password = (string) $request->request->get('password', '');
            if ($username === '' || $password === '') {
                $error = (string) __('login.error_required');
            } elseif (sw_login_is_locked_out($ip, $username)) {
                $error = (string) __('login.error_locked');
            } else {
                $row = sw_find_physician_by_username($username);
                if ($row && password_verify($password, $row['password_hash'])) {
                    if (password_needs_rehash($row['password_hash'], PASSWORD_BCRYPT)) {
                        sw_set_password((int) $row['id'], $password);
                    }
                    sw_login_user($row);
                    sw_login_clear_throttle($ip, $username);
                    PhiLogger::append('login_success', null, null, null);
                    $nextRaw = trim((string) $request->query->get('next', '/index.php'));
                    $next = BackUrl::safeAppRedirectTarget($nextRaw, '/index.php');

                    return $this->redirect($next);
                }
                sw_login_record_failure($ip, $username);
                PhiLogger::append('login_failed', null, null, null);
                $error = (string) __('login.error_invalid');
            }
        }

        return $this->render('auth/login.html.twig', [
            'error' => $error,
            'username' => $username,
            'csrf_token' => sw_csrf_token(),
            'html_lang' => SwLocale::htmlLang(),
        ]);
    }
}
