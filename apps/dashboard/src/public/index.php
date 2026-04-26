<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

if (!defined('SW_ROOT')) {
    define('SW_ROOT', dirname(__DIR__));
}

if (!defined('SW_SYMFONY_HTTP')) {
    define('SW_SYMFONY_HTTP', true);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/config/bootstrap.php';

$kernel = new Kernel(
    (string) ($_SERVER['APP_ENV'] ?? 'dev'),
    filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)
);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
