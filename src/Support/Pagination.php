<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;

final class Pagination
{
    public const MAX_PER_PAGE = 100;

    /**
     * @param array<string, string> $query
     * @return array{0: int, 1: int}
     */
    public static function fromQuery(array $query): array
    {
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $per = filter_var($query['per_page'] ?? '20', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_PER_PAGE]]);
        if ($page === false || $per === false) {
            throw HttpException::badRequest('page must be >= 1 and per_page between 1 and ' . self::MAX_PER_PAGE . '.');
        }

        return [$page, $per];
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int} */
    public static function meta(int $page, int $perPage, int $total): array
    {
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / $perPage)];
    }
}
