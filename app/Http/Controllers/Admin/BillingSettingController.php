<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBillingSettingRequest;
use App\Http\Requests\Admin\UpdateBillingSettingRequest;
use App\Http\Resources\ProviderBillingSettingResource;
use App\Models\ProviderBillingSetting;
use App\Services\Admin\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingSettingController extends Controller
{
    public function __construct(protected BillingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = ProviderBillingSetting::query()
            ->when($request->filled('provider_type'), fn ($q) => $q->where('provider_type', $request->provider_type))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('provider_id', $request->provider_id))
            ->when($request->filled('billing_type'), fn ($q) => $q->where('billing_type', $request->billing_type))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->latest();

        $settings = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => ProviderBillingSettingResource::collection($settings),
            'meta' => [
                'total' => $settings->total(),
                'per_page' => $settings->perPage(),
                'current_page' => $settings->currentPage(),
            ],
        ]);
    }

    public function store(StoreBillingSettingRequest $request): JsonResponse
    {
        $setting = $this->service->createSetting($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء إعداد الفوترة بنجاح',
            'data' => new ProviderBillingSettingResource($setting),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $setting = ProviderBillingSetting::find($id);

        if (!$setting) {
            return response()->json(['success' => false, 'message' => 'إعداد الفوترة غير موجود'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new ProviderBillingSettingResource($setting),
        ]);
    }

    public function update(UpdateBillingSettingRequest $request, int $id): JsonResponse
    {
        $setting = ProviderBillingSetting::find($id);

        if (!$setting) {
            return response()->json(['success' => false, 'message' => 'إعداد الفوترة غير موجود'], 404);
        }

        $setting = $this->service->updateSetting($setting, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث إعداد الفوترة بنجاح',
            'data' => new ProviderBillingSettingResource($setting),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $setting = ProviderBillingSetting::find($id);

        if (!$setting) {
            return response()->json(['success' => false, 'message' => 'إعداد الفوترة غير موجود'], 404);
        }

        $setting->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف إعداد الفوترة بنجاح',
        ]);
    }
}
