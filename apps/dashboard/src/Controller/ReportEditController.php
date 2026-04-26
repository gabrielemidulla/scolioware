<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\BackUrl;
use App\Locale\SwLocale;
use App\Report\ReportEditMetricsService;
use App\Report\ReportPageService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReportEditController extends BaseController
{
    public function __construct(
        private readonly ReportPageService $reportPage,
        private readonly ReportEditMetricsService $editMetrics,
    ) {
    }

    #[Route('/report_edit_metrics.php', name: 'report_edit_metrics', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $id = (int) $request->query->get('id', 0);
        if ($id <= 0 && $request->request->has('report_id')) {
            $id = (int) $request->request->get('report_id');
        }
        if ($id <= 0) {
            throw new BadRequestHttpException((string) __('edit.err_id'));
        }

        $row = $this->reportPage->fetchReportRow($id);
        if ($row === null) {
            throw new NotFoundHttpException((string) __('edit.err_nf'));
        }

        if (!$this->editMetrics->isEditable($row)) {
            return $this->render('report_edit/metrics_locked.html.twig', [
                'user' => $user,
                'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
                'html_lang' => SwLocale::htmlLang(),
                'csrf_token' => sw_csrf_token(),
                'nav_route' => 'report_edit_metrics',
                'id' => $id,
                'status' => (string) ($row['status'] ?? ''),
            ]);
        }

        $postedBack = $request->request->has('back') ? (string) $request->request->get('back') : null;
        $safeBack = BackUrl::validate($postedBack) ?? BackUrl::validate(BackUrl::getRaw());

        $errors = [];
        $form = $this->editMetrics->formDefaults($row);

        if ($request->isMethod('POST')) {
            sw_csrf_check();
            $r = $this->editMetrics->validateAndBuildPayload($request, $row);
            if ($r['ok']) {
                $this->editMetrics->apply($id, $r['payload']);
                $q = ['id' => $id];
                if ($safeBack !== null && $safeBack !== '') {
                    $q['back'] = $safeBack;
                }

                return new RedirectResponse($this->generateUrl('report', $q));
            }
            $errors = $r['errors'];
            $form = $r['form'];
        }

        return $this->render('report_edit/metrics_form.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'report_edit_metrics',
            'id' => $id,
            'row' => $row,
            'errors' => $errors,
            'form' => $form,
            'back_raw' => BackUrl::getRaw(),
        ]);
    }
}
