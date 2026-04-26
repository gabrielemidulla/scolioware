<?php

declare(strict_types=1);

namespace App\Controller;

use App\Locale\SwLocale;
use App\Pdf\NewPdfPageService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class NewPdfController extends BaseController
{
    public function __construct(private readonly NewPdfPageService $newPdfPage)
    {
    }

    #[Route('/new_pdf.php', name: 'new_pdf', methods: ['GET', 'POST'])]
    public function show(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $reportId = $this->newPdfPage->resolveReportId($request);
        if ($reportId <= 0) {
            throw new BadRequestHttpException((string) __('error.missing_report_id_param'));
        }

        $report = $this->newPdfPage->fetchReport($reportId);
        if ($report === null) {
            throw new NotFoundHttpException((string) __('error.report_not_found'));
        }

        $errors = [];
        $formTitle = '';
        $formNotes = '';

        if ($request->isMethod('POST')) {
            sw_csrf_check();
            $result = $this->newPdfPage->processPost($request, $report, $user);
            if ($result['redirect']) {
                $q = ['id' => $result['report_id']];
                if ($result['back'] !== null && $result['back'] !== '') {
                    $q['back'] = $result['back'];
                }
                $url = $this->generateUrl('report', $q) . '#pdf-' . (int) $result['pdf_id'];

                return new RedirectResponse($url);
            }
            $errors = $result['errors'];
            $formTitle = $result['form_title'];
            $formNotes = $result['form_notes'];
        }

        $defaultTitle = (string) __('new_pdf.default_title', [
            'rid' => (string) (int) $report['id'],
            'surname' => (string) $report['last_name'],
            'name' => (string) $report['first_name'],
        ]);

        return $this->render('new_pdf/form.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'new_pdf',
            'report_back_fallback' => 'report.php?id=' . (int) $report['id'],
            'report' => $report,
            'errors' => $errors,
            'form_title' => $formTitle,
            'form_notes' => $formNotes,
            'default_title' => $defaultTitle,
            'include_image' => $this->newPdfPage->includeImageCheckbox($request, $report),
        ]);
    }
}
