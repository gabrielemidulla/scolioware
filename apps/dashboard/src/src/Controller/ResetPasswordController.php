<?php

declare(strict_types=1);

namespace App\Controller;

use App\Locale\SwLocale;

use App\Auth\ResetPasswordPageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ResetPasswordController extends AbstractController
{
    public function __construct(private readonly ResetPasswordPageService $resetPasswordPage)
    {
    }

    #[Route('/reset_password.php', name: 'reset_password', methods: ['GET', 'POST'])]
    public function show(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $token = $this->resetPasswordPage->resolveToken($request);
        $row = $this->resetPasswordPage->lookupToken($token);

        $error = '';
        $success = false;

        if ($row === null) {
            $error = (string) __('reset.error_token');
        }

        if ($row !== null && $request->isMethod('POST')) {
            sw_csrf_check();
            $r = $this->resetPasswordPage->handlePasswordPost($request, $row);
            if ($r['success']) {
                $success = true;
            } else {
                $error = $r['error'];
            }
        }

        return $this->render('reset_password/page.html.twig', [
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'token' => $token,
            'row' => $row,
            'error' => $error,
            'success' => $success,
            'min_password_len' => SW_MIN_PASSWORD_LEN,
        ]);
    }
}
