<?php

namespace App\Http\Controllers\FuelOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\FuelOrder\StoreFuelOrderRequest;
use App\Http\Resources\FuelOrderResource;
use App\Services\FuelOrderService;
use Illuminate\Http\Request;

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
}