<?php

declare(strict_types=1);

namespace App\Exception;

/** Used when legacy auth needs a redirect under Symfony. */
final class AuthRedirectException extends \RuntimeException
{
    public function __construct(public readonly string $targetUrl)
    {
        parent::__construct('Redirect: ' . $targetUrl);
    }
}
