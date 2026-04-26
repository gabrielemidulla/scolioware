<?php

declare(strict_types=1);

namespace App\Controller;

use App\Locale\SwLocale;
use App\Patient\NewReportPageService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewReportController extends BaseController
{
    public function __construct(private readonly NewReportPageService $newReportPage)
    {
    }

    #[Route('/new_report.php', name: 'new_report', methods: ['GET'])]
    public function show(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $patients = $this->newReportPage->listPatientsForSelect();

        return $this->render('new_report/show.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'new_report',
            'patients' => $patients,
            'report_url_zero' => $this->generateUrl('report', ['id' => 0]),
        ]);
    }
}
