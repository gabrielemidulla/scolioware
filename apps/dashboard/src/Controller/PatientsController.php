<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Locale\SwLocale;
use App\Http\BackUrl;
use App\Patient\PatientsPageService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PatientsController extends BaseController
{
    public function __construct(private readonly PatientsPageService $patientsPage)
    {
    }

    #[Route('/patients.php', name: 'patients', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();

        $createFlash = '';
        $createFlashType = 'danger';

        if ($request->isMethod('POST') && $request->request->get('create_patient')) {
            sw_csrf_check();
            try {
                $newId = $this->patientsPage->createPatient($request);
                PhiLogger::append('create_patient', $newId, null, null);

                return new RedirectResponse($this->generateUrl('patients'));
            } catch (\RuntimeException $e) {
                $createFlash = $e->getMessage();
            } catch (\Throwable) {
                $createFlash = (string) __('patients.err_create');
            }
        }

        $data = $this->patientsPage->loadList($request);
        $paginationQuery = $request->query->all();

        $backRaw = BackUrl::getRaw();
        $backOk = $backRaw !== null && BackUrl::validate($backRaw) !== null;

        return $this->render('patients/index.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'patients',
            'rows' => $data['rows'],
            'totalPatients' => $data['totalPatients'],
            'page' => $data['page'],
            'totalPages' => $data['totalPages'],
            'from' => $data['from'],
            'to' => $data['to'],
            'pHtMin' => $data['pHtMin'],
            'pHtMax' => $data['pHtMax'],
            'pWtMin' => $data['pWtMin'],
            'pWtMax' => $data['pWtMax'],
            'pagination_query' => $paginationQuery,
            'back_raw' => $backRaw,
            'back_ok' => $backOk,
            'create_flash' => $createFlash,
            'create_flash_type' => $createFlashType,
        ]);
    }
}
