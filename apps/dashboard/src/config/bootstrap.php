<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env') || is_file(dirname(__DIR__) . '/.env.local')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}
