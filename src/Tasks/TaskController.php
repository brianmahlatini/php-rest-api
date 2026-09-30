<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Pagination;
use App\Support\Validator;

final class TaskController
{
    public const STATUSES = ['todo', 'in_progress', 'done'];

    public function __construct(private readonly TaskRepository $tasks) {}

    public function index(Request $request): Response
    {
        [$page, $perPage] = Pagination::fromQuery($request->query);
        $filters = [];
        if (!self::isAdmin($request) || ($request->query['scope'] ?? '') !== 'all') {
            $filters['owner_id'] = self::userId($request); // members only ever see their own tasks
        }
        if (isset($request->query['status'])) {
            if (!in_array($request->query['status'], self::STATUSES, true)) {
                throw HttpException::badRequest('status must be one of: ' . implode(', ', self::STATUSES));
            }
            $filters['status'] = $request->query['status'];
        }
        if (isset($request->query['q'])) {
            $filters['q'] = mb_substr($request->query['q'], 0, 100);
        }
        $result = $this->tasks->search($filters, $page, $perPage);

        return Response::json(['data' => $result['items'], 'meta' => Pagination::meta($page, $perPage, $result['total'])]);
    }

    public function show(Request $request): Response
    {
        return Response::json(['data' => $this->authorized($request)]);
    }

    public function store(Request $request): Response
    {
        $data = self::rules(Validator::make($request->json()))->validate();
        $id = $this->tasks->create(self::userId($request), $data);

        return Response::json(['data' => $this->tasks->find($id)], 201)->withHeader('location', "/api/tasks/{$id}");
    }

    public function update(Request $request): Response
    {
        $task = $this->authorized($request);
        $data = self::rules(Validator::partial($request->json()))->validate();
        $this->tasks->update((int) $task['id'], $data);

        return Response::json(['data' => $this->tasks->find((int) $task['id'])]);
    }

    public function destroy(Request $request): Response
    {
        $task = $this->authorized($request);
        $this->tasks->delete((int) $task['id']);

        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function authorized(Request $request): array
    {
        $task = $this->tasks->find((int) $request->params['id']);
        // 404 rather than 403 for other people's tasks: don't reveal that the id exists.
        if ($task === null || ($task['owner_id'] !== self::userId($request) && !self::isAdmin($request))) {
            throw HttpException::notFound('Task not found.');
        }

        return $task;
    }

    private static function rules(Validator $v): Validator
    {
        return $v->string('title', min: 1, max: 200)
            ->string('description', max: 5000, required: false)
            ->in('status', self::STATUSES, required: false)
            ->int('priority', 1, 5, required: false)
            ->date('due_date', required: false);
    }

    private static function userId(Request $r): int
    {
        return (int) $r->attribute('user_id');
    }

    private static function isAdmin(Request $r): bool
    {
        return $r->attribute('role') === 'admin';
    }
}
