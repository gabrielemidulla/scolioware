<?php

declare(strict_types=1);

namespace App\Controller;

use App\Storage\R2Storage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** R2 proxy; only valid with internal nginx + INTERNAL_PROXY. */
final class InternalR2BlobController
{
    #[Route('/internal_r2_blob.php', name: 'internal_r2_blob', methods: ['GET', 'PUT', 'POST', 'DELETE'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->reachedViaInternalProxy($request)) {
            return new Response('Not Found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $op = strtolower(trim((string) $request->query->get('op', '')));
        $key = (string) $request->query->get('key', '');

        if (!R2Storage::isInternalKeyAllowed($key)) {
            return new Response('Invalid or disallowed object key.', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if (!R2Storage::isConfigured()) {
            return new Response('R2 is not configured.', Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        try {
            if ($op === 'get') {
                $bytes = R2Storage::getBytes($key);
                if ($bytes === null) {
                    return new Response('', Response::HTTP_NOT_FOUND);
                }

                return new Response($bytes, Response::HTTP_OK, [
                    'Content-Type' => 'application/octet-stream',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'no-store',
                ]);
            }

            if ($op === 'put') {
                $method = $request->getMethod();
                if ($method !== 'PUT' && $method !== 'POST') {
                    return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'PUT, POST']);
                }
                $raw = (string) $request->getContent();
                $ct = $request->headers->get('Content-Type', 'application/octet-stream');
                $ct = trim($ct) !== '' ? trim($ct) : 'application/octet-stream';
                R2Storage::putBytes($key, $raw, $ct);

                return new Response('', Response::HTTP_NO_CONTENT);
            }

            if ($op === 'delete') {
                if ($request->getMethod() !== 'DELETE') {
                    return new Response('', Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'DELETE']);
                }
                R2Storage::deleteObject($key);

                return new Response('', Response::HTTP_NO_CONTENT);
            }
        } catch (\Throwable $e) {
            error_log('internal_r2_blob: ' . $e->getMessage());

            return new Response('Storage error.', Response::HTTP_BAD_GATEWAY, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return new Response(
            'Unknown op; use get, put, or delete.',
            Response::HTTP_BAD_REQUEST,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    private function reachedViaInternalProxy(Request $request): bool
    {
        return ((string) $request->server->get('INTERNAL_PROXY', '')) === '1';
    }
}
