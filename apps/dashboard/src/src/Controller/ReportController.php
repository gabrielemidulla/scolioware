<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Http\BackUrl;
use App\Locale\SwLocale;
use App\Report\ReportDeletionService;
use App\Report\ReportPageService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends BaseController
{
    public function __construct(
        private readonly ReportPageService $reportPage,
        private readonly ReportDeletionService $reportDeletion,
    ) {
    }

    #[Route('/report.php', name: 'report', methods: ['GET', 'POST'])]
    public function show(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $flashReportDeleteFail = (string) ($_SESSION['sw_report_delete_fail'] ?? '');
        if ($flashReportDeleteFail !== '') {
            unset($_SESSION['sw_report_delete_fail']);
        }

        $id = (int) $request->query->get('id', 0);
        if ($id <= 0) {
            throw new BadRequestHttpException((string) __('report.err_id'));
        }

        $postedBack = $request->request->has('back') ? (string) $request->request->get('back') : null;
        $safeBack = BackUrl::validate($postedBack) ?? BackUrl::validate(BackUrl::getRaw());

        $vitalsErrors = [];

        if ($request->isMethod('POST') && $request->request->has('save_report_vitals')) {
            sw_csrf_check();
            if ((int) $request->request->get('report_id') !== $id) {
                throw new BadRequestHttpException((string) __('report.err_invalid_id'));
            }
            $parsed = $this->reportPage->parseVitalsInput(
                (string) $request->request->get('height_cm', ''),
                (string) $request->request->get('weight_kg', '')
            );
            $vitalsErrors = $parsed['errors'];
            if ($vitalsErrors === []) {
                $rep = $this->reportPage->fetchReportRow($id);
                if ($rep === null) {
                    throw new NotFoundHttpException((string) __('report.err_nf'));
                }
                $patientId = $this->reportPage->applyVitalsUpdate($id, $parsed['hDb'], $parsed['wDb']);
                $this->reportPage->refreshPatientLastVitals($patientId);
                $q = ['id' => $id, 'vitals' => 'ok'];
                if ($safeBack !== null) {
                    $q['back'] = $safeBack;
                }

                return new RedirectResponse($this->generateUrl('report', $q));
            }
        }

        $row = $this->reportPage->fetchReportRow($id);
        if ($row === null) {
            throw new NotFoundHttpException((string) __('report.err_nf'));
        }

        if ($request->isMethod('POST') && $request->request->has('admin_delete_report')) {
            sw_csrf_check();
            if (!sw_is_admin()) {
                throw new AccessDeniedHttpException((string) __('error.forbidden_admin'));
            }
            if ((int) $request->request->get('report_id') !== $id) {
                throw new BadRequestHttpException((string) __('report.err_invalid_id'));
            }
            try {
                $this->reportDeletion->deleteReportWithStorage($id, $row);
            } catch (\RuntimeException $e) {
                $_SESSION['sw_report_delete_fail'] = $e->getMessage();
                $q = ['id' => $id];
                if ($safeBack !== null) {
                    $q['back'] = $safeBack;
                }

                return new RedirectResponse($this->generateUrl('report', $q));
            }
            PhiLogger::append('admin_delete_report', (int) $row['patient_id'], $id, null);
            $q = ['id' => (int) $row['patient_id'], 'admin_rep_deleted' => '1'];
            if ($safeBack !== null) {
                $q['back'] = $safeBack;
            }

            return new RedirectResponse($this->generateUrl('patient', $q));
        }

        PhiLogger::append('view_report', (int) $row['patient_id'], $id, null);

        $canReviseMetrics = $row['status'] === 'failed' || $row['status'] === 'completed';
        $hasBack = BackUrl::validate(BackUrl::getRaw()) !== null;
        $isAdmin = sw_is_admin();

        $pdfBlock = $this->reportPage->loadPdfSection($request, $id);
        $vitalsState = $this->reportPage->vitalsDisplayState($row, $vitalsErrors, $request);
        $pdfCountLine = $this->reportPage->buildPdfCountLine(
            $pdfBlock['pdfTotalCount'],
            $pdfBlock['pdfPage'],
            $pdfBlock['pdfTotalPages']
        );

        $pdfPaginationQuery = $request->query->all();
        $reportPageJs = 'assets/js/report-page.js';
        $reportPageJsPath = SW_ROOT . '/public/' . $reportPageJs;
        if (is_file($reportPageJsPath)) {
            $reportPageJs .= '?v=' . (string) filemtime($reportPageJsPath);
        }

        $reportI18n = [
            'dash' => (string) __('common.dash'),
            'uCm' => (string) __('patient.unit_cm'),
            'uKg' => (string) __('patient.unit_kg'),
            'status' => [
                'pending' => (string) __('status.pending'),
                'processing' => (string) __('status.processing'),
                'completed' => (string) __('status.completed'),
                'failed' => (string) __('status.failed'),
            ],
            'curve' => [
                'C' => (string) __('curve.opt_c'),
                'S' => (string) __('curve.opt_s'),
            ],
            'js_orig' => (string) __('report.js_orig'),
            'js_overlay' => (string) __('report.js_overlay'),
            'js_waiting' => (string) __('report.js_waiting'),
            'js_failed' => (string) __('report.js_failed'),
            'js_poll_err' => (string) __('report.js_poll_err'),
            'lmk_count' => (string) __('lmk.count'),
            'lmk_min' => (string) __('lmk.err_min'),
            'lmk_no_orig' => (string) __('lmk.err_no_orig'),
            'lmk_load_err' => (string) __('lmk.err_load'),
            'lmk_load_konva_err' => (string) __('lmk.err_konva'),
            'lmk_saving' => (string) __('lmk.saving'),
            'lmk_save_ok' => (string) __('lmk.save_ok'),
            'lmk_confirm_close' => (string) __('lmk.confirm_close'),
            'lmk_confirm_reset' => (string) __('lmk.confirm_reset'),
            'llm_generate' => (string) __('llm.generate'),
            'llm_regenerate' => (string) __('llm.regenerate'),
            'llm_generating' => (string) __('llm.generating'),
            'llm_queued' => (string) __('llm.queued'),
            'llm_running' => (string) __('llm.running'),
            'llm_load_err' => (string) __('llm.err_load'),
            'llm_gen_err' => (string) __('llm.err_gen'),
            'llm_meta' => (string) __('llm.meta'),
            'llm_confirm_regen' => (string) __('llm.confirm_regen'),
        ];
        $reportPageConfig = [
            'reportId' => $id,
            'csrf_token' => sw_csrf_token(),
            'i18n' => $reportI18n,
        ];
        $reportPageConfigJson = json_encode($reportPageConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
        if ($reportPageConfigJson === false) {
            $reportPageConfigJson = '{}';
        }

        $patientBreadcrumbLabel = $row['last_name'] . ', ' . $row['first_name'];
        $backRaw = BackUrl::getRaw();

        return $this->render('report/show.html.twig', [
            'user' => $user,
            'is_admin' => $isAdmin,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'report',
            'id' => $id,
            'row' => $row,
            'patient_breadcrumb_label' => $patientBreadcrumbLabel,
            'has_back' => $hasBack,
            'can_revise_metrics' => $canReviseMetrics,
            'vitals_errors' => $vitalsErrors,
            'vitals_saved_ok' => $request->query->get('vitals') === 'ok',
            'landmarks_recompute_ok' => $request->query->get('landmarks') === 'ok',
            'flash_report_delete_fail' => $flashReportDeleteFail,
            'show_vitals' => $vitalsState['showVitals'],
            'vitals_text' => $vitalsState['vitalsText'],
            'vitals_form_h' => $vitalsState['vitalsFormH'],
            'vitals_form_w' => $vitalsState['vitalsFormW'],
            'pdf_reports' => $pdfBlock['pdfReports'],
            'pdf_total_count' => $pdfBlock['pdfTotalCount'],
            'pdf_page' => $pdfBlock['pdfPage'],
            'pdf_total_pages' => $pdfBlock['pdfTotalPages'],
            'pdf_count_line' => $pdfCountLine,
            'pdf_pagination_query' => $pdfPaginationQuery,
            'report_page_js' => $reportPageJs,
            'report_page_config_json' => $reportPageConfigJson,
            'back_raw' => $backRaw,
        ]);
    }
}
