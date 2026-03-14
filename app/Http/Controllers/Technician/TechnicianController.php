<?php

namespace App\Http\Controllers\Technician;

use App\Http\Controllers\Controller;
use App\Http\Requests\Technician\UpdateTechnicianProfileRequest;
use App\Http\Resources\TechnicianResource;
use App\Services\TechnicianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TechnicianController extends Controller
{
    public function __construct(
        protected TechnicianService $technicianService
    ) {}

    //عرض الملف الشخصي
    public function profile(Request $request): JsonResponse
    {
        try {
            $technician = $this->technicianService->getProfile($request->user());

            if (!$technician) {
                return response()->json([
                    'success' => false,
                    'message' => 'أنت لست تقنياً بعد. يرجى إكمال ملفك الشخصي'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new TechnicianResource($technician)
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    //تحديث الملف الشخصي
    public function updateProfile(UpdateTechnicianProfileRequest $request): JsonResponse
    {
        try {
            Log::info('Update profile request received', [
                'method' => $request->method(),
                'data' => $request->all()
            ]);

            $data = $request->validated();

            if ($request->hasFile('certifications')) {
                $data['certifications'] = $request->file('certifications');
            }

            $technician = $this->technicianService->updateProfile(
                $request->user(),
                $data
            );

            return response()->json([
                'success' => true,
                'message' => $technician->wasRecentlyCreated
                    ? 'تم إنشاء ملفك كتقني بنجاح'
                    : 'تم تحديث الملف الشخصي بنجاح',
                'data' => new TechnicianResource($technician)
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], 500);
        }
    }

    //تحديث حالة التوفر
    public function updateAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'is_available' => ['required', 'boolean']
        ]);

        try {
            $this->technicianService->updateAvailability(
                $request->user(),
                $request->is_available
            );

            return response()->json([
                'success' => true,
                'message' => $request->is_available
                    ? 'أنت الآن متاح للعمل'
                    : 'أنت الآن غير متاح للعمل'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating availability: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
