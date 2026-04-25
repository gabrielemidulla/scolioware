<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('MYSQL_HOST') ?: 'mysql';
    $port = getenv('MYSQL_PORT') ?: '3306';
    $name = getenv('MYSQL_DATABASE') ?: 'php_commerce';
    $user = getenv('MYSQL_USER') ?: 'app_user';
    $pass = getenv('MYSQL_PASSWORD') ?: 'app_password';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
