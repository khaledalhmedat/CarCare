<?php

namespace App\Http\Controllers\FuelOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelOrder\StoreFuelOrderRequest;
use App\Http\Resources\FuelOrderResource;
use App\Services\FuelOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\FuelOrder\StoreEmergencyFuelOrderRequest;


class FuelOrderController extends Controller
{
    public function __construct(protected FuelOrderService $service) {}

    public function index(Request $request)
    {
        $orders = $this->service->getUserOrders($request->user(), $request->status);
        return response()->json(['success' => true, 'data' => FuelOrderResource::collection($orders)]);
    }

    public function store(StoreFuelOrderRequest $request)
    {
        $order = $this->service->createOrder($request->user(), $request->validated());
        return response()->json(['success' => true, 'message' => 'تم إنشاء طلب الوقود بنجاح', 'data' => new FuelOrderResource($order)], 201);
    }

    public function show(Request $request, int $id)
    {
        $order = $this->service->getOrder($id, $request->user());
        return response()->json(['success' => true, 'data' => new FuelOrderResource($order)]);
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate(['cancellation_reason' => 'required|string|min:5']);
        $this->service->cancelOrder($id, $request->user(), $request->cancellation_reason);
        return response()->json(['success' => true, 'message' => 'تم إلغاء الطلب بنجاح']);
    }

    public function emergency(StoreEmergencyFuelOrderRequest $request)
    {
        try {
            $order = $this->service->createEmergencyOrder(
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'message' => 'تم إرسال طلب الوقود الطارئ بنجاح',
                'data' => new FuelOrderResource($order)
            ], 201);
        } catch (\Throwable $e) {
            Log::warning('fuel.emergency_order.create_failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء إنشاء طلب الوقود، حاول مرة أخرى'
            ], 500);
        }
    }


    public function trackProvider(Request $request, int $id)
    {
        $order = $this->service->getOrder($id, $request->user());

        if (!$order->fuel_provider_id) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم تعيين مزود وقود لهذا الطلب بعد'
            ], 404);
        }

        $provider = $order->fuelProvider;

        $trackingPoints = $order->trackingPoints()
            ->orderBy('created_at', 'asc')
            ->get(['latitude', 'longitude', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'order_id' => $order->id,
                'order_status' => $order->status,
                'provider' => [
                    'id' => $provider->id,
                    'company_name' => $provider->company_name,
                    'phone' => $provider->phone,
                ],
                'current_location' => [
                    'latitude' => $provider->latitude ? (float) $provider->latitude : null,
                    'longitude' => $provider->longitude ? (float) $provider->longitude : null,
                    'last_updated' => $provider->updated_at?->toDateTimeString(),
                ],
                'tracking_path' => $trackingPoints->map(function ($point) {
                    return [
                        'latitude' => (float) $point->latitude,
                        'longitude' => (float) $point->longitude,
                        'timestamp' => $point->created_at->toDateTimeString(),
                    ];
                }),
            ]
        ]);
    }
}
