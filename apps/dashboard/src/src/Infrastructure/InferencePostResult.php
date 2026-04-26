<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * JSON response from an inference internal POST.
 */
final readonly class InferencePostResult
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $httpStatus,
        public array $body,
    ) {
    }
}
