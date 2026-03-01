<?php

namespace App\Repositories\Implementation;

use App\Models\User;
use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthRepository implements AuthRepositoryInterface
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {}

    public function register(array $data): User
    {
        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'status' => 'active',
        ];

        return $this->userRepository->create($userData);
    }

    public function login(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);
        
        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الاعتماد هذه لا تتطابق مع سجلاتنا.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw new \Exception('الحساب غير نشط، يرجى التواصل مع الدعم');
        }

        return $user;
    }

    public function logout(User $user): bool
    {
        return $user->currentAccessToken()->delete() > 0;
    }

    public function createToken(User $user, string $name = 'auth_token'): string
    {
        $this->revokeAllTokens($user);
        
        return $user->createToken($name)->plainTextToken;
    }

    public function revokeAllTokens(User $user): bool
    {
        return $user->tokens()->delete() > 0;
    }
}