<?php

declare(strict_types=1);

namespace App;

use App\Auth\AuthController;
use App\Auth\Jwt;
use App\Http\Kernel;
use App\Http\Response;
use App\Http\Router;
use App\Middleware\Authenticate;
use App\Middleware\Cors;
use App\Middleware\ErrorHandler;
use App\Middleware\RateLimit;
use App\Middleware\RequireRole;
use App\Middleware\SecurityHeaders;
use App\Tasks\TaskController;
use App\Tasks\TaskRepository;
use App\Users\AdminController;
use App\Users\UserRepository;

/** Composition root: the only place objects are wired together. */
final class App
{
    /** @param array{jwt_secret: string, cors_origins: list<string>, debug?: bool, clock?: callable(): float} $config */
    public static function build(\PDO $pdo, array $config): Kernel
    {
        $jwt = new Jwt($config['jwt_secret']);
        $users = new UserRepository($pdo);
        $auth = new AuthController($users, $jwt);
        $tasks = new TaskController(new TaskRepository($pdo));
        $admin = new AdminController($users);
        $clock = $config['clock'] ?? null;

        $authn = new Authenticate($jwt);
        $authLimit = new RateLimit($pdo, 'auth', capacity: 10, refillPerSecond: 10 / 60, clock: $clock); // 10/min burst
        $apiLimit = new RateLimit($pdo, 'api', capacity: 120, refillPerSecond: 2.0, clock: $clock);

        $r = new Router();
        $r->add('GET', '/health', static fn(): Response => Response::json(['status' => 'ok']));
        $r->add('POST', '/api/auth/register', $auth->register(...), [$authLimit]);
        $r->add('POST', '/api/auth/login', $auth->login(...), [$authLimit]);
        $r->add('GET', '/api/auth/me', $auth->me(...), [$authn]);

        $protected = [$apiLimit, $authn];
        $r->add('GET', '/api/tasks', $tasks->index(...), $protected);
        $r->add('POST', '/api/tasks', $tasks->store(...), $protected);
        $r->add('GET', '/api/tasks/{id}', $tasks->show(...), $protected);
        $r->add('PATCH', '/api/tasks/{id}', $tasks->update(...), $protected);
        $r->add('DELETE', '/api/tasks/{id}', $tasks->destroy(...), $protected);

        $adminOnly = [...$protected, new RequireRole('admin')];
        $r->add('GET', '/api/admin/users', $admin->listUsers(...), $adminOnly);
        $r->add('PATCH', '/api/admin/users/{id}', $admin->updateRole(...), $adminOnly);

        return new Kernel($r, [
            new ErrorHandler($config['debug'] ?? false),
            new SecurityHeaders(),
            new Cors($config['cors_origins']),
        ]);
    }
}
