<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\App;
use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Users\UserRepository;
use PHPUnit\Framework\TestCase;

/** Full HTTP stack against a fresh in-memory SQLite database per test. */
final class ApiTest extends TestCase
{
    private Kernel $app;
    private \PDO $pdo;
    private float $now = 1_700_000_000.0;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::create('sqlite::memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->app = App::build($this->pdo, [
            'jwt_secret' => str_repeat('s', 40),
            'cors_origins' => ['https://app.example.test'],
            'clock' => fn(): float => $this->now,
        ]);
    }

    /**
     * @param array<string, mixed>|null $json
     * @param array<string, string>      $headers
     */
    private function call(string $method, string $path, ?array $json = null, ?string $token = null, array $headers = [], string $ip = '10.0.0.1'): Response
    {
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        $h = $headers + ($token !== null ? ['authorization' => "Bearer {$token}"] : []);

        /** @var array<string, string> $query */
        return $this->app->handle(new Request($method, $p, $query, $h, $json === null ? '' : (string) json_encode($json), clientIp: $ip));
    }

    private function register(string $email, string $ip = '10.0.0.1'): string
    {
        $r = $this->call('POST', '/api/auth/register', ['email' => $email, 'password' => 'correct horse battery', 'name' => 'Test User'], ip: $ip);
        self::assertSame(201, $r->status, $r->body);

        return (string) $r->decoded()['token'];
    }

    public function testRegisterLoginAndMe(): void
    {
        $this->register('Ada@Example.test');
        $login = $this->call('POST', '/api/auth/login', ['email' => 'ada@example.test', 'password' => 'correct horse battery']);
        self::assertSame(200, $login->status);
        self::assertArrayNotHasKey('password_hash', $login->decoded()['user']);

        $me = $this->call('GET', '/api/auth/me', token: (string) $login->decoded()['token']);
        self::assertSame('ada@example.test', $me->decoded()['user']['email']); // normalised to lower case
    }

    public function testLoginFailuresAreIndistinguishable(): void
    {
        $this->register('bob@example.test');
        $wrongPw = $this->call('POST', '/api/auth/login', ['email' => 'bob@example.test', 'password' => 'nope']);
        $noUser = $this->call('POST', '/api/auth/login', ['email' => 'nobody@example.test', 'password' => 'nope']);
        self::assertSame(401, $wrongPw->status);
        self::assertSame($wrongPw->decoded()['detail'], $noUser->decoded()['detail']);
    }

    public function testDuplicateEmailIsConflict(): void
    {
        $this->register('dup@example.test');
        $r = $this->call('POST', '/api/auth/register', ['email' => 'DUP@example.test', 'password' => 'another long password', 'name' => 'X']);
        self::assertSame(409, $r->status);
    }

    public function testTaskCrudAndPagination(): void
    {
        $t = $this->register('crud@example.test');
        for ($i = 1; $i <= 3; $i++) {
            $r = $this->call('POST', '/api/tasks', ['title' => "Task {$i}", 'priority' => $i], $t);
            self::assertSame(201, $r->status);
        }
        $id = (int) $r->decoded()['data']['id'];
        self::assertSame("/api/tasks/{$id}", $r->headers['location']);

        $page = $this->call('GET', '/api/tasks?per_page=2', token: $t)->decoded();
        self::assertCount(2, $page['data']);
        self::assertSame(['page' => 1, 'per_page' => 2, 'total' => 3, 'total_pages' => 2], $page['meta']);
        self::assertSame('Task 1', $page['data'][0]['title']); // priority 1 first

        $patched = $this->call('PATCH', "/api/tasks/{$id}", ['status' => 'done', 'due_date' => '2026-12-31'], $t)->decoded()['data'];
        self::assertSame('done', $patched['status']);
        self::assertSame('Task 3', $patched['title']); // untouched fields kept

        self::assertSame(1, count($this->call('GET', '/api/tasks?status=done', token: $t)->decoded()['data']));
        self::assertSame(204, $this->call('DELETE', "/api/tasks/{$id}", token: $t)->status);
        self::assertSame(404, $this->call('GET', "/api/tasks/{$id}", token: $t)->status);
    }

    public function testUsersCannotSeeOrTouchEachOthersTasks(): void
    {
        $alice = $this->register('alice@example.test');
        $mallory = $this->register('mallory@example.test');
        $id = $this->call('POST', '/api/tasks', ['title' => 'private'], $alice)->decoded()['data']['id'];

        self::assertSame(404, $this->call('GET', "/api/tasks/{$id}", token: $mallory)->status);
        self::assertSame(404, $this->call('PATCH', "/api/tasks/{$id}", ['title' => 'pwned'], $mallory)->status);
        self::assertSame(404, $this->call('DELETE', "/api/tasks/{$id}", token: $mallory)->status);
        self::assertSame([], $this->call('GET', '/api/tasks', token: $mallory)->decoded()['data']);
        self::assertSame([], $this->call('GET', '/api/tasks?scope=all', token: $mallory)->decoded()['data']); // scope=all is admin-only
    }

    public function testMassAssignmentIsIgnored(): void
    {
        $t = $this->register('mass@example.test');
        $task = $this->call('POST', '/api/tasks', ['title' => 'x', 'owner_id' => 999], $t)->decoded()['data'];
        self::assertNotSame(999, $task['owner_id']);
    }

    public function testValidationErrorsUseProblemDetails(): void
    {
        $t = $this->register('val@example.test');
        $r = $this->call('POST', '/api/tasks', ['title' => '', 'status' => 'nope', 'priority' => 0], $t);
        self::assertSame(422, $r->status);
        self::assertSame('application/problem+json', $r->headers['content-type']);
        self::assertSame(['title', 'status', 'priority'], array_keys($r->decoded()['errors']));

        $bad = $this->app->handle(new Request('POST', '/api/tasks', [], ['authorization' => "Bearer {$t}"], '{not json'));
        self::assertSame(400, $bad->status);
    }

    public function testAuthIsRequiredAndTamperingRejected(): void
    {
        self::assertSame(401, $this->call('GET', '/api/tasks')->status);
        $t = $this->register('tamper@example.test');
        $r = $this->call('GET', '/api/tasks', token: $t . 'x');
        self::assertSame(401, $r->status);
        self::assertSame('Bearer', $r->headers['www-authenticate']);
    }

    public function testAdminEndpointsEnforceRoleAndProtectLastAdmin(): void
    {
        $member = $this->register('member@example.test');
        self::assertSame(403, $this->call('GET', '/api/admin/users', token: $member)->status);

        $users = new UserRepository($this->pdo);
        $admin = $users->findByEmail('member@example.test');
        self::assertNotNull($admin);
        $users->setRole($admin->id, 'admin');
        $adminToken = (string) $this->call('POST', '/api/auth/login', ['email' => 'member@example.test', 'password' => 'correct horse battery'])->decoded()['token'];

        $list = $this->call('GET', '/api/admin/users', token: $adminToken)->decoded();
        self::assertSame(1, $list['meta']['total']);

        $r = $this->call('PATCH', "/api/admin/users/{$admin->id}", ['role' => 'member'], $adminToken);
        self::assertSame(409, $r->status); // last admin cannot be demoted
    }

    public function testAuthEndpointsAreRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(401, $this->call('POST', '/api/auth/login', ['email' => 'x@example.test', 'password' => 'y'], ip: '203.0.113.9')->status);
        }
        $limited = $this->call('POST', '/api/auth/login', ['email' => 'x@example.test', 'password' => 'y'], ip: '203.0.113.9');
        self::assertSame(429, $limited->status);
        self::assertSame('6', $limited->headers['retry-after']);
        self::assertSame(401, $this->call('POST', '/api/auth/login', ['email' => 'x@example.test', 'password' => 'y'], ip: '203.0.113.10')->status); // other IPs unaffected

        $this->now += 6; // one token refilled
        self::assertSame(401, $this->call('POST', '/api/auth/login', ['email' => 'x@example.test', 'password' => 'y'], ip: '203.0.113.9')->status);
    }

    public function testCorsPreflightAndSecurityHeaders(): void
    {
        $ok = $this->call('OPTIONS', '/api/tasks', headers: ['origin' => 'https://app.example.test', 'access-control-request-method' => 'POST']);
        self::assertSame(204, $ok->status);
        self::assertSame('https://app.example.test', $ok->headers['access-control-allow-origin']);

        $evil = $this->call('OPTIONS', '/api/tasks', headers: ['origin' => 'https://evil.example', 'access-control-request-method' => 'POST']);
        self::assertArrayNotHasKey('access-control-allow-origin', $evil->headers);

        $health = $this->call('GET', '/health');
        self::assertSame('nosniff', $health->headers['x-content-type-options']);
        self::assertArrayHasKey('x-request-id', $health->headers);
    }

    public function testUnknownRouteAndMethod(): void
    {
        self::assertSame(404, $this->call('GET', '/nope')->status);
        $r = $this->call('PUT', '/api/tasks');
        self::assertSame(405, $r->status);
        self::assertSame('GET, POST', $r->headers['allow']);
    }

    public function testMigrationsAreIdempotent(): void
    {
        self::assertSame([], (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate());
    }
}
