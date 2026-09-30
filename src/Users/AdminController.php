<?php

declare(strict_types=1);

namespace App\Users;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Pagination;
use App\Support\Validator;

final class AdminController
{
    public function __construct(private readonly UserRepository $users) {}

    public function listUsers(Request $request): Response
    {
        [$page, $perPage] = Pagination::fromQuery($request->query);
        $result = $this->users->paginate($page, $perPage);

        return Response::json([
            'data' => array_map(static fn(User $u): array => $u->toPublic(), $result['items']),
            'meta' => Pagination::meta($page, $perPage, $result['total']),
        ]);
    }

    public function updateRole(Request $request): Response
    {
        $id = (int) $request->params['id'];
        $data = Validator::make($request->json())->in('role', ['member', 'admin'])->validate();
        $user = $this->users->find($id) ?? throw HttpException::notFound('User not found.');

        // Never allow the system to lose its last administrator.
        if ($user->role === 'admin' && $data['role'] === 'member' && $this->users->countAdmins() <= 1) {
            throw HttpException::conflict('Cannot demote the last remaining admin.');
        }
        $this->users->setRole($id, (string) $data['role']);

        return Response::json(['user' => ($this->users->find($id) ?? $user)->toPublic()]);
    }
}
