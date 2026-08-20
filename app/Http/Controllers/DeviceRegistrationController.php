<?php

namespace App\Http\Controllers;

use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Services\DeviceRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceRegistrationController extends Controller
{
    public function __construct(private DeviceRegistrationService $devices)
    {
    }

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $registration = $this->devices->registerDevice($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الجهاز بنجاح',
            'data' => [
                'id' => $registration->id,
                'platform' => $registration->platform,
                'device_id' => $registration->device_id,
                'is_active' => $registration->is_active,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => ['required', 'string', 'max:255'],
        ]);

        $this->devices->unregisterDevice($request->user(), $request->input('fcm_token'));

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء تسجيل الجهاز',
        ]);
    }
}
