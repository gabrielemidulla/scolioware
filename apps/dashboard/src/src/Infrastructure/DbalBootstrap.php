<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * Exposes the shared DBAL {@see Connection} for Symfony DI autowiring.
 */
final class DbalBootstrap
{
    public function __invoke(): Connection
    {
        return Database::dbal();
    }
}
