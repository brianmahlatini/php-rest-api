<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;
use App\Users\UserRepository;

final class AuthController
{
    // Precomputed so unknown-email logins cost the same as wrong-password ones
    // (no user enumeration through response timing).
    private const DUMMY_HASH = '$2y$12$Um63LUzof3utd645v.Rx7uj638SF6N1RdX72vyQBS9fI1jDANPJYa';

    public function __construct(private readonly UserRepository $users, private readonly Jwt $jwt) {}

    public function register(Request $request): Response
    {
        $data = Validator::make($request->json())
            ->email('email')
            ->string('password', min: 12, max: 128)
            ->string('name', min: 1, max: 100)
            ->validate();

        $email = strtolower((string) $data['email']);
        if ($this->users->findByEmail($email) !== null) {
            throw HttpException::conflict('An account with this email already exists.');
        }
        $hash = password_hash((string) $data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $user = $this->users->create($email, (string) $data['name'], $hash, 'member');

        return Response::json(['user' => $user->toPublic(), 'token' => $this->token($user->id, $user->role)], 201);
    }

    public function login(Request $request): Response
    {
        $data = Validator::make($request->json())->email('email')->string('password', min: 1, max: 128)->validate();
        $user = $this->users->findByEmail(strtolower((string) $data['email']));
        $valid = password_verify((string) $data['password'], $user->passwordHash ?? self::DUMMY_HASH);
        if ($user === null || !$valid) {
            throw HttpException::unauthorized('Invalid email or password.'); // same message either way
        }
        if (password_needs_rehash($user->passwordHash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $this->users->updatePasswordHash($user->id, password_hash((string) $data['password'], PASSWORD_BCRYPT, ['cost' => 12]));
        }

        return Response::json(['user' => $user->toPublic(), 'token' => $this->token($user->id, $user->role)]);
    }

    public function me(Request $request): Response
    {
        $user = $this->users->find((int) $request->attribute('user_id'));

        return $user === null ? throw HttpException::unauthorized() : Response::json(['user' => $user->toPublic()]);
    }

    private function token(int $userId, string $role): string
    {
        return $this->jwt->issue(['sub' => (string) $userId, 'role' => $role]);
    }
}
