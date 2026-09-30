<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\HttpException;
use App\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testCollectsAllErrors(): void
    {
        try {
            Validator::make(['email' => 'nope', 'priority' => 9])->email('email')->string('title', min: 1)->int('priority', 1, 5)->validate();
            self::fail('expected validation error');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(['email', 'title', 'priority'], array_keys($e->extra['errors']));
        }
    }

    public function testReturnsOnlyDeclaredFields(): void
    {
        $clean = Validator::make(['title' => '  Buy milk ', 'role' => 'admin', 'owner_id' => 1])->string('title', min: 1)->validate();
        self::assertSame(['title' => 'Buy milk'], $clean); // trimmed; mass-assignment fields dropped
    }

    public function testPartialAllowsMissingButValidatesPresent(): void
    {
        self::assertSame([], Validator::partial([])->string('title', min: 1)->validate());
        $this->expectException(HttpException::class);
        Validator::partial(['title' => ''])->string('title', min: 1)->validate();
    }

    public function testDateMustBeRealCalendarDate(): void
    {
        self::assertSame(['d' => '2026-02-28'], Validator::make(['d' => '2026-02-28'])->date('d')->validate());
        $this->expectException(HttpException::class);
        Validator::make(['d' => '2026-02-30'])->date('d')->validate();
    }
}
