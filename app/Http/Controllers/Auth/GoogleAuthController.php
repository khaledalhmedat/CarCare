<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\GoogleAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Services\Auth\GoogleAuthService;
use Illuminate\Http\JsonResponse;

class GoogleAuthController extends Controller
{
    public function __construct(protected GoogleAuthService $service) {}

    public function login(GoogleLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->service->loginWithIdToken($request->validated()['id_token']);
        } catch (GoogleAuthException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'user' => $result['user'],
                'token' => $result['token'],
                'token_type' => 'Bearer',
            ],
        ], 200);
    }
}
