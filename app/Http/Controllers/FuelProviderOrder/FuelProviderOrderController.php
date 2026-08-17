<?php

namespace App\Http\Controllers\FuelProviderOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelProvider\AcceptFuelOrderRequest;
use App\Http\Requests\FuelProvider\UpdateFuelOrderStatusRequest;
use App\Http\Requests\FuelProvider\CancelFuelOrderRequest;
use App\Http\Requests\FuelProvider\UpdateLocationRequest;
use App\Http\Resources\FuelOrderResource;
use App\Services\FuelProviderOrderService;
use Illuminate\Http\Request;

class FuelProviderOrderController extends Controller
{
    public function __construct(protected FuelProviderOrderService $orderService) {}


    public function availableOrders(Request $request)
    {
        $provider = $request->user()->fuelProvider;

        $orders = $this->orderService->getAvailableOrders($provider);

        return response()->json([
            'success' => true,
            'data' => FuelOrderResource::collection($orders),
        ]);
    }


    public function showOrder(int $orderId)
    {
        $order = $this->orderService->getOrderDetails($orderId);

        return response()->json([
            'success' => true,
            'data' => new FuelOrderResource($order)
        ]);
    }


    public function acceptOrder(AcceptFuelOrderRequest $request, int $orderId)
    {
        $order = $this->orderService->acceptOrder(
            $request->user(),
            $orderId,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم قبول طلب الوقود بنجاح',
            'data' => new FuelOrderResource($order)
        ]);
    }


    public function shareLocation(UpdateLocationRequest $request, int $orderId)
    {
        $this->orderService->shareLocation(
            $request->user(),
            $orderId,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الموقع'
        ]);
    }


    public function updateOrderStatus(UpdateFuelOrderStatusRequest $request, int $orderId)
    {
        $order = $this->orderService->updateOrderStatus(
            $request->user(),
            $orderId,
            $request->status
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الطلب بنجاح',
            'data' => new FuelOrderResource($order)
        ]);
    }


    public function cancelOrder(CancelFuelOrderRequest $request, int $orderId)
    {
        $order = $this->orderService->cancelOrder(
            $request->user(),
            $orderId,
            $request->cancellation_reason
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الطلب وإعادة فتحه',
            'data' => new FuelOrderResource($order)
        ]);
    }


    public function myOrders(Request $request)
    {
        $orders = $this->orderService->getMyOrders($request->user(), $request->status);

        return response()->json([
            'success' => true,
            'data' => FuelOrderResource::collection($orders),
            'meta' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
            ]
        ]);
    }
}
