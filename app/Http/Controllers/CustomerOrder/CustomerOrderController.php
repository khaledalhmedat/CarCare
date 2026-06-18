<?php

namespace App\Http\Controllers\CustomerOrder;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartRequest;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Services\CustomerShopService;
use Illuminate\Http\Request;

class CustomerOrderController extends Controller
{
    public function __construct(protected CustomerShopService $shopService) {}

    // ========== Cart ==========
    public function cart(Request $request)
    {
        $cart = $this->shopService->getCart($request->user());
        
        return response()->json([
            'success' => true,
            'data' => CartResource::collection($cart),
            'total' => $cart->sum('subtotal'),
        ]);
    }

    public function addToCart(AddToCartRequest $request)
    {
        $cartItem = $this->shopService->addToCart(
            $request->user(),
            $request->product_id,
            $request->quantity
        );
        
        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المنتج للسلة',
            'data' => new CartResource($cartItem)
        ]);
    }

    public function updateCart(UpdateCartRequest $request, int $id)
    {
        $this->shopService->updateCart($request->user(), $id, $request->quantity);
        
        return response()->json([
            'success' => true,
            'message' => 'تم تحديث السلة'
        ]);
    }

    public function removeFromCart(Request $request, int $id)
    {
        $this->shopService->removeFromCart($request->user(), $id);
        
        return response()->json([
            'success' => true,
            'message' => 'تم حذف العنصر من السلة'
        ]);
    }

    // ========== Orders ==========
    public function createOrder(Request $request)
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'address_note' => ['nullable', 'string', 'max:500'],
        ]);
        
        $order = $this->shopService->createOrder($request->user(), $validated);
        
        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الطلب بنجاح',
            'data' => new OrderResource($order)
        ], 201);
    }

    public function orders(Request $request)
    {
        $orders = $this->shopService->getUserOrders($request->user(), $request->status);
        
        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'meta' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
            ]
        ]);
    }

    public function orderDetails(Request $request, int $id)
    {
        $order = $this->shopService->getOrderDetails($id, $request->user());
        
        return response()->json([
            'success' => true,
            'data' => new OrderResource($order)
        ]);
    }

    public function cancelOrder(Request $request, int $id)
    {
        $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5'],
        ]);
        
        $this->shopService->cancelOrder($id, $request->user(), $request->cancellation_reason);
        
        return response()->json([
            'success' => true,
            'message' => 'تم إلغاء الطلب بنجاح'
        ]);
    }
}