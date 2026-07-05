<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreShopRequest;
use App\Http\Requests\Shop\UpdateShopRequest;
use App\Http\Requests\Shop\StoreProductRequest;
use App\Http\Requests\Shop\UpdateProductRequest;
use App\Http\Resources\ShopResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Services\ShopService;
use Illuminate\Http\Request;
use App\Http\Requests\Shop\UpdateOrderStatusRequest;
use App\Http\Requests\Shop\ShareDeliveryLocationRequest;

class ShopController extends Controller
{
    public function __construct(protected ShopService $shopService) {}

    /**
     * عرض معلومات متجري
     */
    public function profile(Request $request)
    {
        $shop = $this->shopService->getProfile($request->user());
        
        if (!$shop) {
            return response()->json([
                'success' => false,
                'message' => 'لم تقم بإدخال معلومات متجرك بعد'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => new ShopResource($shop)
        ]);
    }

    /**
     * إضافة/تحديث معلومات المتجر
     */
    public function storeOrUpdateProfile(StoreShopRequest $request)
    {
        $shop = $this->shopService->createOrUpdateShop($request->user(), $request->validated());
        
        return response()->json([
            'success' => true,
            'message' => $shop->wasRecentlyCreated ? 'تم إنشاء المتجر بنجاح' : 'تم تحديث المتجر بنجاح',
            'data' => new ShopResource($shop)
        ]);
    }

    /**
     * عرض منتجاتي
     */
    public function products(Request $request)
    {
        $products = $this->shopService->getShopProducts($request->user());
        
        return response()->json([
            'success' => true,
            'data' => ProductResource::collection($products),
            'meta' => [
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
            ]
        ]);
    }

    /**
     * إضافة منتج
     */
    public function storeProduct(StoreProductRequest $request)
    {
        $data = $request->validated();
        $images = $request->hasFile('images') ? $request->file('images') : null;
        
        $product = $this->shopService->createProduct($request->user(), $data, $images);
        
        return response()->json([
            'success' => true,
            'message' => 'تم إضافة المنتج بنجاح',
            'data' => new ProductResource($product)
        ], 201);
    }

    /**
     * تعديل منتج
     */
    public function updateProduct(UpdateProductRequest $request, int $id)
    {
        $data = $request->validated();
        $images = $request->hasFile('images') ? $request->file('images') : null;
        
        $product = $this->shopService->updateProduct($request->user(), $id, $data, $images);
        
        return response()->json([
            'success' => true,
            'message' => 'تم تعديل المنتج بنجاح',
            'data' => new ProductResource($product)
        ]);
    }

    /**
     * حذف منتج
     */
    public function deleteProduct(Request $request, int $id)
    {
        $this->shopService->deleteProduct($request->user(), $id);
        
        return response()->json([
            'success' => true,
            'message' => 'تم حذف المنتج بنجاح'
        ]);
    }

    /**
 * عرض الطلبيات الواردة
 */
public function orders(Request $request)
{
    $orders = $this->shopService->getShopOrders($request->user(), $request->status);
    
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

/**
 * عرض تفاصيل طلبية واردة
 */
public function orderDetails(Request $request, int $id)
{
    $order = $this->shopService->getShopOrder($request->user(), $id);
    
    return response()->json([
        'success' => true,
        'data' => new OrderResource($order)
    ]);
}

/**
 * قبول طلبية
 */
public function acceptOrder(Request $request, int $id)
{
    $order = $this->shopService->acceptOrder($request->user(), $id);
    
    return response()->json([
        'success' => true,
        'message' => 'تم قبول الطلبية بنجاح',
        'data' => new OrderResource($order)
    ]);
}

/**
 * رفض طلبية
 */
public function rejectOrder(Request $request, int $id)
{
    $request->validate(['reason' => 'required|string|min:5']);
    
    $order = $this->shopService->rejectOrder($request->user(), $id, $request->reason);
    
    return response()->json([
        'success' => true,
        'message' => 'تم رفض الطلبية',
        'data' => new OrderResource($order)
    ]);
}

/**
 * تحديث حالة الطلبية (processing, out_for_delivery, delivered)
 */
public function updateOrderStatus(UpdateOrderStatusRequest $request, int $id)
{
    $order = $this->shopService->updateOrderStatus(
        $request->user(),
        $id,
        $request->status,
        $request->notes
    );
    
    return response()->json([
        'success' => true,
        'message' => 'تم تحديث حالة الطلبية بنجاح',
        'data' => new OrderResource($order)
    ]);
}

/**
 * مشاركة موقع المندوب
 */
public function shareDeliveryLocation(ShareDeliveryLocationRequest $request, int $id)
{
    $point = $this->shopService->shareDeliveryLocation(
        $request->user(),
        $id,
        $request->latitude,
        $request->longitude
    );
    
    return response()->json([
        'success' => true,
        'message' => 'تم مشاركة الموقع',
        'data' => [
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'timestamp' => $point->created_at->toDateTimeString(),
        ]
    ]);
}

/**
 * تتبع موقع التوصيل (للمستخدم)
 */
public function trackDelivery(Request $request, int $id)
{
    $tracking = $this->shopService->getDeliveryTracking($request->user(), $id);
    
    return response()->json([
        'success' => true,
        'data' => $tracking
    ]);
}
}