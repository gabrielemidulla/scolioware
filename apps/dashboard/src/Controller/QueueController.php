<?php

declare(strict_types=1);

namespace App\Controller;

use App\Audit\PhiLogger;
use App\Locale\SwLocale;
use App\Http\BackUrl;
use App\Queue\QueuePageService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class QueueController extends BaseController
{
    public function __construct(private readonly QueuePageService $queuePage)
    {
    }

    #[Route('/queue.php', name: 'queue', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        sw_session_start();
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        $user = sw_require_auth();
        PhiLogger::append('view_queue', null, null, null);

        $data = $this->queuePage->load($request);
        $f = $data['f'];

        $paginationQuery = $request->query->all();
        unset($paginationQuery['_fragment']);

        $backRaw = BackUrl::getRaw();
        $backOk = $backRaw !== null && BackUrl::validate($backRaw) !== null;

        if ($request->query->get('_fragment') === '1') {
            $response = $this->render('queue/_fragment.html.twig', [
                'rows' => $data['rows'],
                'totalReports' => $data['totalReports'],
                'page' => $data['page'],
                'totalPages' => $data['totalPages'],
                'from' => $data['from'],
                'to' => $data['to'],
                'pagination_query' => $paginationQuery,
            ]);
            $response->headers->set('X-Scolioware-Queue-Fragment', 'ok');

            return $response;
        }

        return $this->render('queue/index.html.twig', [
            'user' => $user,
            'is_admin' => (int) ($user['is_admin'] ?? 0) === 1,
            'html_lang' => SwLocale::htmlLang(),
            'csrf_token' => sw_csrf_token(),
            'nav_route' => 'queue',
            'f' => $f,
            'rows' => $data['rows'],
            'totalReports' => $data['totalReports'],
            'page' => $data['page'],
            'perPage' => $data['perPage'],
            'totalPages' => $data['totalPages'],
            'from' => $data['from'],
            'to' => $data['to'],
            'allPatients' => $data['allPatients'],
            'patientId' => $data['patientId'],
            'tax' => $data['tax'],
            'pagination_query' => $paginationQuery,
            'back_raw' => $backRaw,
            'back_ok' => $backOk,
            'allowed_status' => ['pending', 'processing', 'completed', 'failed'],
        ]);
    }
}
