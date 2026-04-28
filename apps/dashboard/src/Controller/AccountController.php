<?php

declare(strict_types=1);

namespace App\Controller;

use App\Locale\SwLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountController extends AbstractController
{
    #[Route('/account.php', name: 'account', methods: ['GET', 'POST'])]
    public function show(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $success = '';
        $error = '';
        $mustChange = $request->query->get('must_change') === '1';

        if ($request->isMethod('POST')) {
            sw_csrf_check();
            $current = (string) $request->request->get('current_password', '');
            $new1 = (string) $request->request->get('new_password', '');
            $new2 = (string) $request->request->get('new_password_confirm', '');

            if (!password_verify($current, (string) $user['password_hash'])) {
                $error = (string) __('account.error_current');
            } elseif ($new1 !== $new2) {
                $error = (string) __('account.error_mismatch');
            } elseif (($pwErr = sw_validate_password($new1)) !== null) {
                $error = $pwErr;
            } else {
                sw_set_password((int) $user['id'], $new1);
                $success = (string) __('account.success');
                $refreshed = sw_find_physician((int) $user['id']);
                if ($refreshed !== null) {
                    $user = $refreshed;
                }
            }
        }

        return $this->render('account/index.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'success' => $success,
            'error' => $error,
            'must_change' => $mustChange,
            'csrf_token' => sw_csrf_token(),
            'min_pw_len' => SW_MIN_PASSWORD_LEN,
            'html_lang' => SwLocale::htmlLang(),
            'nav_route' => 'account',
        ]);
    }
}
