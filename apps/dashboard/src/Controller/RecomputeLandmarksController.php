<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\RecomputeLandmarksHandler;
use App\Locale\SwLocale;
use App\Exception\ApiJsonTerminator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecomputeLandmarksController extends AbstractController
{
    public function __construct(private readonly RecomputeLandmarksHandler $handler)
    {
    }

    #[Route('/recompute_landmarks.php', name: 'recompute_landmarks', methods: ['POST'])]
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

        throw new \LogicException('recompute_landmarks: unexpected fall-through');
    }
}
