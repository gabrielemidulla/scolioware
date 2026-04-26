<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Infrastructure\Database;
use App\Locale\SwLocale;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends BaseController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    #[Route('/index.php', name: 'home_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();
        PhiLogger::append('view_dashboard', null, null, null);

        try {
            Database::pdo()->query('SELECT 1');
            $dbOk = true;
        } catch (\Throwable $e) {
            $dbOk = false;
            error_log('dashboard DB check: ' . $e->getMessage());
        }

        return $this->render('home/index.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'db_ok' => $dbOk,
            'nav_route' => $request->attributes->get('_route'),
        ]);
    }
}
