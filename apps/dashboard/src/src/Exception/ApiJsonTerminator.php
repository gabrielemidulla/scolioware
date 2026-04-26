<?php

declare(strict_types=1);

namespace App\Exception;

/** Stops the request after writing JSON (Twig include flow). */
final class ApiJsonTerminator extends \RuntimeException
{
    public function __construct(
        int $httpStatus,
        public readonly string $jsonBody,
    ) {
        parent::__construct('', $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
