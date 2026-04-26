<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Locale\SwLocale;
use App\Http\BackUrl;
use App\I18n\DashboardLabels;
use App\Patient\PatientPageService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PatientController extends BaseController
{
    public function __construct(private readonly PatientPageService $patientPage)
    {
    }

    #[Route('/patient.php', name: 'patient', methods: ['GET', 'POST'])]
    public function show(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $flashPatientDeleteFail = (string) ($_SESSION['sw_patient_delete_fail'] ?? '');
        if ($flashPatientDeleteFail !== '') {
            unset($_SESSION['sw_patient_delete_fail']);
        }

        $id = (int) $request->query->get('id', 0);
        if ($id <= 0) {
            throw new BadRequestHttpException((string) __('patient.err_missing_id'));
        }

        $patient = $this->patientPage->fetchPatient($id);
        if ($patient === null) {
            throw new NotFoundHttpException((string) __('patient.err_not_found'));
        }

        $patientReportCountAll = $this->patientPage->countAllReportsForPatient($id);

        if ($request->isMethod('POST') && $request->request->has('admin_delete_patient')) {
            sw_csrf_check();
            if (!sw_is_admin()) {
                throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException((string) __('error.forbidden_admin'));
            }
            if ((int) $request->request->get('patient_id') !== $id) {
                throw new BadRequestHttpException((string) __('error.missing_patient_id'));
            }
            if ($patientReportCountAll > 0) {
                $_SESSION['sw_patient_delete_fail'] = (string) __('patient.admin_delete_blocked');

                return new RedirectResponse($this->generateUrl('patient', ['id' => $id]));
            }
            $this->patientPage->deletePatient($id);
            PhiLogger::append('admin_delete_patient', $id, null, null);

            return new RedirectResponse($this->generateUrl('patients', ['pat_deleted' => '1']));
        }

        PhiLogger::append('view_patient', $id, null, null);

        $reportsBlock = $this->patientPage->loadReportsSection($request, $id);
        $f = $reportsBlock['f'];

        $paginationQuery = $request->query->all();
        $backRaw = BackUrl::getRaw();
        $backOk = $backRaw !== null && BackUrl::validate($backRaw) !== null;
        $hasBack = $backOk;
        $topBackHref = $hasBack ? BackUrl::orDefault('patients.php') : $this->generateUrl('patients');

        $rowsPart = $reportsBlock['totalReports'] > 0
            ? (string) __('patient.reports_count_rows', ['from' => $reportsBlock['from'], 'to' => $reportsBlock['to']])
            : '';
        $reportsLine = (string) __('patient.reports_count_wrap', [
            'n' => $reportsBlock['totalReports'],
            'rows' => $rowsPart,
            'newest' => (string) __('patient.reports_sub'),
        ]);
        $unitCm = (string) __('patient.unit_cm');
        $unitKg = (string) __('patient.unit_kg');

        $patientDisplayName = $patient['last_name'] . ', ' . $patient['first_name'];
        $genderLabel = DashboardLabels::gender((string) $patient['gender']);

        return $this->render('patient/show.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'patient',
            'id' => $id,
            'patient' => $patient,
            'patient_display_name' => $patientDisplayName,
            'gender_label' => $genderLabel,
            'patient_report_count_all' => $patientReportCountAll,
            'report_deleted_ok' => $request->query->get('admin_rep_deleted') === '1',
            'flash_patient_delete_fail' => $flashPatientDeleteFail,
            'f' => $f,
            'reports' => $reportsBlock['reports'],
            'total_reports' => $reportsBlock['totalReports'],
            'page' => $reportsBlock['page'],
            'per_page' => $reportsBlock['perPage'],
            'total_pages' => $reportsBlock['totalPages'],
            'from' => $reportsBlock['from'],
            'to' => $reportsBlock['to'],
            'chart_rows' => $reportsBlock['chartRows'],
            'chart_data' => $reportsBlock['chartData'],
            'reports_line' => $reportsLine,
            'unit_cm' => $unitCm,
            'unit_kg' => $unitKg,
            'pagination_query' => $paginationQuery,
            'back_raw' => $backRaw,
            'back_ok' => $backOk,
            'has_back' => $hasBack,
            'top_back_href' => $topBackHref,
            'allowed_status' => ['pending', 'processing', 'completed', 'failed'],
        ]);
    }
}
