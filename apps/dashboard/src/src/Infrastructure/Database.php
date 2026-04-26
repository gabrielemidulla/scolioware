<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PDO;

/**
 * MySQL access via Doctrine DBAL. {@see self::pdo()} exposes the underlying PDO for legacy-style callers.
 */
final class Database
{
    private static ?Connection $connection = null;

    /**
     * @return array<string, mixed>
     */
    private static function connectionParams(): array
    {
        $host = getenv('MYSQL_HOST') ?: 'mysql';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $name = getenv('MYSQL_DATABASE') ?: 'php_commerce';
        $user = getenv('MYSQL_USER') ?: 'app_user';
        $pass = getenv('MYSQL_PASSWORD') ?: 'app_password';

        return [
            'driver' => 'pdo_mysql',
            'host' => $host,
            'port' => (int) $port,
            'dbname' => $name,
            'user' => $user,
            'password' => $pass,
            'charset' => 'utf8mb4',
            'driverOptions' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            ],
        ];
    }

    public static function dbal(): Connection
    {
        if (self::$connection instanceof Connection) {
            return self::$connection;
        }

        self::$connection = DriverManager::getConnection(self::connectionParams());

        return self::$connection;
    }

    public static function pdo(): PDO
    {
        $native = self::dbal()->getNativeConnection();
        if (!$native instanceof PDO) {
            throw new \RuntimeException('Expected PDO from pdo_mysql DBAL driver.');
        }

        return $native;
    }
}
