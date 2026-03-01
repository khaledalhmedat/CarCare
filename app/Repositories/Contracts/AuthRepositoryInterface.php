<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface AuthRepositoryInterface
{
    public function register(array $data): User;
    public function login(string $email, string $password): ?User;
    public function logout(User $user): bool;
    public function createToken(User $user, string $name = 'auth_token'): string;
    public function revokeAllTokens(User $user): bool;
}