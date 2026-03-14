<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Resources\ProfileResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profileService
    ) {}

    //عرض البروفايل
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['vehicles', 'maintenanceRequests' => function ($q) {
            $q->latest()->limit(5);
        }]);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب البيانات بنجاح',
            'data' => new ProfileResource($user)
        ]);
    }

    //تحديث البيانات الشخصية
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        try {
            $user = $this->profileService->updateProfile(
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث البيانات بنجاح',
                'data' => new ProfileResource($user)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث البيانات',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //تحديث كلمة المرور
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        try {
            $this->profileService->updatePassword(
                $request->user(),
                $request->new_password
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث كلمة المرور بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث كلمة المرور',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //تحديث الصورة الشخصية
    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        try {
            $path = $this->profileService->updateAvatar(
                $request->user(),
                $request->file('avatar')
            );

            return response()->json([
                'success' => true,
                'message' => 'تم تحديث الصورة بنجاح',
                'data' => [
                    'avatar_url' => asset('storage/' . $path)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء تحديث الصورة',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //تحديث الصورة الشخصية
    public function deleteAvatar(Request $request): JsonResponse
    {
        try {
            $this->profileService->deleteAvatar($request->user());

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الصورة بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حذف الصورة',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    //حذف الحساب
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string']
        ]);

        try {
            $this->profileService->deleteAccount(
                $request->user(),
                $request->password
            );

            return response()->json([
                'success' => true,
                'message' => 'تم حذف الحساب بنجاح'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
