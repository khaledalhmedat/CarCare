<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdvertisementRequest;
use App\Http\Requests\Admin\UpdateAdvertisementRequest;
use App\Http\Resources\AdvertisementResource;
use App\Models\Advertisement;
use App\Services\Admin\AdvertisementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvertisementController extends Controller
{
    public function __construct(protected AdvertisementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Advertisement::query()
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('placement'), fn ($q) => $q->where('placement', $request->placement))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->orderBy('sort_order')
            ->latest();

        $ads = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => AdvertisementResource::collection($ads),
            'meta' => [
                'total' => $ads->total(),
                'per_page' => $ads->perPage(),
                'current_page' => $ads->currentPage(),
            ],
        ]);
    }

    public function store(StoreAdvertisementRequest $request): JsonResponse
    {
        $ad = $this->service->create(
            $request->safe()->except('image'),
            $request->file('image'),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الإعلان بنجاح',
            'data' => new AdvertisementResource($ad),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $ad = Advertisement::find($id);

        if (!$ad) {
            return response()->json(['success' => false, 'message' => 'الإعلان غير موجود'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new AdvertisementResource($ad),
        ]);
    }

    public function update(UpdateAdvertisementRequest $request, int $id): JsonResponse
    {
        $ad = Advertisement::find($id);

        if (!$ad) {
            return response()->json(['success' => false, 'message' => 'الإعلان غير موجود'], 404);
        }

        $ad = $this->service->update(
            $ad,
            $request->safe()->except('image'),
            $request->file('image'),
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الإعلان بنجاح',
            'data' => new AdvertisementResource($ad),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $ad = Advertisement::find($id);

        if (!$ad) {
            return response()->json(['success' => false, 'message' => 'الإعلان غير موجود'], 404);
        }

        $this->service->delete($ad);

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الإعلان بنجاح',
        ]);
    }

    public function activate(Request $request, int $id): JsonResponse
    {
        return $this->toggle($request, $id, true, 'تم تفعيل الإعلان');
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        return $this->toggle($request, $id, false, 'تم إلغاء تفعيل الإعلان');
    }

    private function toggle(Request $request, int $id, bool $isActive, string $message): JsonResponse
    {
        $ad = Advertisement::find($id);

        if (!$ad) {
            return response()->json(['success' => false, 'message' => 'الإعلان غير موجود'], 404);
        }

        $ad = $this->service->setActive($ad, $isActive, $request->user()->id);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new AdvertisementResource($ad),
        ]);
    }
}
