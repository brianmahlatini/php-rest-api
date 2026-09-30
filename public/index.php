<?php

declare(strict_types=1);

use App\App;
use App\Database\ConnectionFactory;
use App\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static fn(string $k, ?string $default = null): ?string => ($v = getenv($k)) === false ? $default : $v;

$secret = $env('JWT_SECRET') ?? throw new RuntimeException('JWT_SECRET is not set');
$pdo = ConnectionFactory::create((string) $env('DB_DSN', 'sqlite:' . dirname(__DIR__) . '/var/app.sqlite'), $env('DB_USER'), $env('DB_PASSWORD'));

App::build($pdo, [
    'jwt_secret' => $secret,
    'cors_origins' => array_values(array_filter(explode(',', (string) $env('CORS_ORIGINS', 'http://localhost:5173,http://localhost:4200')))),
    'debug' => $env('APP_DEBUG') === '1',
])->handle(Request::fromGlobals())->send();
