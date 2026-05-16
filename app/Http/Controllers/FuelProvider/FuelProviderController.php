<?php

namespace App\Http\Controllers\FuelProvider;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelProvider\UpdateFuelProviderProfileRequest;
use App\Http\Resources\FuelProviderResource;
use App\Services\FuelProviderService;
use Illuminate\Http\Request;

class FuelProviderController extends Controller
{
    public function __construct(protected FuelProviderService $providerService) {}

    public function myProfile(Request $request)
    {
        $provider = $this->providerService->getProfile($request->user());

        if (!$provider) {
            return response()->json([
                'success' => false,
                'message' => 'لم تقم بإدخال معلومات مزود الوقود بعد'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new FuelProviderResource($provider)
        ]);
    }

    public function storeOrUpdateProfile(UpdateFuelProviderProfileRequest $request)
    {
        $provider = $this->providerService->createOrUpdateProfile(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => $provider->wasRecentlyCreated ? 'تم إضافة معلومات مزود الوقود بنجاح' : 'تم تحديث المعلومات بنجاح',
            'data' => new FuelProviderResource($provider)
        ]);
    }

    public function updateAvailability(Request $request)
    {
        $validated = $request->validate(['is_available' => 'required|boolean']);

        $this->providerService->updateAvailability($request->user(), $validated['is_available']);

        return response()->json([
            'success' => true,
            'message' => $validated['is_available'] ? 'أنت متاح الآن' : 'أنت غير متاح حالياً'
        ]);
    }

    public function updatePrices(Request $request)
    {
        $validated = $request->validate([
            'prices' => 'required|array',
            'prices.95' => 'nullable|numeric|min:0',
            'prices.98' => 'nullable|numeric|min:0',
            'prices.diesel' => 'nullable|numeric|min:0',
        ]);

        $this->providerService->updatePrices($request->user(), $validated['prices']);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الأسعار بنجاح'
        ]);
    }

    public function statistics(Request $request)
    {
        $stats = $this->providerService->getStatistics($request->user());

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
