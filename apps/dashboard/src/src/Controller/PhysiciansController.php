<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Locale\SwLocale;
use App\Physician\PhysiciansPageService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PhysiciansController extends BaseController
{
    public function __construct(private readonly PhysiciansPageService $physiciansPage)
    {
    }

    #[Route('/physicians.php', name: 'physicians', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $admin = sw_require_admin();
        PhiLogger::append('view_physicians', null, null, null);

        $flash = '';
        $flashType = 'info';
        $generatedReset = null;

        if ($request->isMethod('POST')) {
            sw_csrf_check();
            $postResult = $this->physiciansPage->handlePost($request, $admin);
            $flash = $postResult['flash'];
            $flashType = $postResult['flash_type'];
            $generatedReset = $postResult['generated_reset'];
        }

        $rows = $this->physiciansPage->listPhysicians();

        return $this->render('physicians/index.html.twig', [
            'user' => $admin,
            'is_admin' => true,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'physicians',
            'admin' => $admin,
            'rows' => $rows,
            'flash' => $flash,
            'flash_type' => $flashType,
            'generated_reset' => $generatedReset,
            'min_password_len' => SW_MIN_PASSWORD_LEN,
        ]);
    }
}
