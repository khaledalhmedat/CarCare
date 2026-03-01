<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    //انشاء حساب 
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->register($request->validated());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'user' => $result['user'],
                    'token' => $result['token'],
                    'token_type' => 'Bearer',
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //تسجيل دخول 
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login($request->validated());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'user' => $result['user'],
                    'token' => $result['token'],
                    'token_type' => 'Bearer',
                ]
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل تسجيل الدخول',
                'errors' => $e->errors()
            ], 401);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //تسجيل خروج
    public function logout(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->logout($request->user());

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message']
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //ملف شخصي
    public function me(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->getCurrentUser($request->user());

            return response()->json([
                'success' => $result['success'],
                'data' => $result['user']
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}