<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseController extends AbstractController
{
    /**
     * @param callable():void $fn Legacy code that echoes HTML (may throw {@see \App\Exception\AuthRedirectException}).
     */
    protected function ssLegacyHtml(callable $fn): Response
    {
        ob_start();
        try {
            $fn();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $content = (string) ob_get_clean();
        $code = http_response_code();
        if ($code < 100 || $code > 599) {
            $code = 200;
        }

        return new Response($content, $code);
    }
}
