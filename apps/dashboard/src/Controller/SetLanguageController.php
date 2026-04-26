<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\BackUrl;
use App\Locale\SwLocale;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SetLanguageController extends AbstractController
{
    #[Route('/set_language.php', name: 'set_language', methods: ['GET'])]
    public function switchLanguage(Request $request): Response
    {
        $lang = strtolower(trim((string) $request->query->get('lang', '')));
        if (!in_array($lang, SwLocale::SUPPORTED, true)) {
            return $this->redirect('index.php');
        }

        $return = BackUrl::safeAppRedirectTarget((string) $request->query->get('return', ''), '/index.php');

        $expires = time() + 365 * 24 * 3600;
        if (PHP_VERSION_ID >= 70300) {
            setcookie(SwLocale::COOKIE_NAME, $lang, [
                'expires' => $expires,
                'path' => '/',
                'secure' => $request->isSecure(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(SwLocale::COOKIE_NAME, $lang, $expires, '/', '', $request->isSecure(), false);
        }

        return $this->redirect($return);
    }
}
