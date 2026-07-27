<?php

// للتذكير: هذا الملف يدير رفع وحذف صورة الملف الشخصي للمستخدم الحالي.

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileImageController extends Controller
{
    public function __construct(protected AuthService $authService) {}

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'profile_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $result = $this->authService->updateProfileImage($request->user(), $request->file('profile_image'));

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => ['user' => $result['user']],
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        $result = $this->authService->deleteProfileImage($request->user());

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => ['user' => $result['user']],
        ]);
    }
}
