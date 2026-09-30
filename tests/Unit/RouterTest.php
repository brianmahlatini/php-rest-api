<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->add('GET', '/api/tasks/{id}', static fn(Request $q): Response => Response::json(['id' => $q->params['id']]));
        $r->add('DELETE', '/api/tasks/{id}', static fn(): Response => Response::noContent());

        return $r;
    }

    public function testExtractsParams(): void
    {
        [$handler, $req] = $this->router()->match(new Request('GET', '/api/tasks/17'));
        self::assertSame(['id' => '17'], $handler($req)->decoded());
    }

    public function testMethodNotAllowedListsAllowedMethods(): void
    {
        try {
            $this->router()->match(new Request('PUT', '/api/tasks/17'));
            self::fail('expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('GET, DELETE', $e->headers['allow']);
        }
    }

    public function testUnknownPathIs404AndSegmentsDoNotSpill(): void
    {
        try {
            $this->router()->match(new Request('GET', '/api/tasks/17/extra'));
            self::fail('expected 404');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }
}
