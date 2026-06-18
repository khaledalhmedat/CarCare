<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreShopRequest;
use App\Http\Requests\Shop\UpdateShopRequest;
use App\Http\Requests\Shop\StoreProductRequest;
use App\Http\Requests\Shop\UpdateProductRequest;
use App\Http\Resources\ShopResource;
use App\Http\Resources\ProductResource;
use App\Services\ShopService;
use Illuminate\Http\Request;

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
}