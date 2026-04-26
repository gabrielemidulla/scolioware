<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\GenerateDraftImpressionHandler;
use App\Locale\SwLocale;
use App\Exception\ApiJsonTerminator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GenerateDraftImpressionController extends AbstractController
{
    public function __construct(private readonly GenerateDraftImpressionHandler $handler)
    {
    }

    #[Route('/generate_draft_impression.php', name: 'generate_draft_impression', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        SwLocale::init();
        $request->setLocale(SwLocale::current());

        try {
            $this->handler->handle($request);
        } catch (ApiJsonTerminator $e) {
            return new Response(
                $e->jsonBody,
                $e->getHttpStatus(),
                ['Content-Type' => 'application/json; charset=UTF-8'],
            );
        }

        throw new \LogicException('generate_draft_impression: unexpected fall-through');
    }
}
