<?php

declare(strict_types=1);

/**
 * Application PDO singleton. All database access should go through {@see Database::pdo()}.
 */
final class Database
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $host = getenv('MYSQL_HOST') ?: 'mysql';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $name = getenv('MYSQL_DATABASE') ?: 'php_commerce';
        $user = getenv('MYSQL_USER') ?: 'app_user';
        $pass = getenv('MYSQL_PASSWORD') ?: 'app_password';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }
}
