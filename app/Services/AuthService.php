<?php

namespace App\Services;

use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        protected AuthRepositoryInterface $authRepository
    ) {}

    public function register(array $data): array
    {
        try {
            $user = $this->authRepository->register($data);
            
            $token = $this->authRepository->createToken($user);

            return [
                'success' => true,
                'user' => new UserResource($user),
                'token' => $token,
                'message' => 'تم إنشاء الحساب بنجاح'
            ];

        } catch (\Exception $e) {
            throw new \Exception('فشل في إنشاء الحساب: ' . $e->getMessage());
        }
    }

    public function login(array $credentials): array
    {
        try {
            $user = $this->authRepository->login(
                $credentials['email'],
                $credentials['password']
            );
            
            $token = $this->authRepository->createToken($user);

            return [
                'success' => true,
                'user' => new UserResource($user->load(['tenant', 'roles'])),
                'token' => $token,
                'message' => 'تم تسجيل الدخول بنجاح'
            ];

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception('فشل في تسجيل الدخول: ' . $e->getMessage());
        }
    }

    public function logout($user): array
    {
        try {
            $this->authRepository->logout($user);

            return [
                'success' => true,
                'message' => 'تم تسجيل الخروج بنجاح'
            ];

        } catch (\Exception $e) {
            throw new \Exception('فشل في تسجيل الخروج: ' . $e->getMessage());
        }
    }

    public function getCurrentUser($user): array
    {
        return [
            'success' => true,
            'user' => new UserResource($user->load(['tenant', 'roles.permissions']))
        ];
    }
}