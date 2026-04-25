<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * @deprecated Prefer {@see Database::pdo()} in new code.
 */
function db(): PDO
{
    return Database::pdo();
}
