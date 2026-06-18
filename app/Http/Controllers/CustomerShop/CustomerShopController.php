<?php

namespace App\Http\Controllers\CustomerShop;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopResource;
use App\Http\Resources\ProductResource;
use App\Services\CustomerShopService;
use Illuminate\Http\Request;

class CustomerShopController extends Controller
{
    public function __construct(protected CustomerShopService $shopService) {}

    public function shops(Request $request)
    {
        $shops = $this->shopService->getShops($request->only(['city', 'business_type_id', 'car_brand_id', 'per_page']));
        
        return response()->json([
            'success' => true,
            'data' => ShopResource::collection($shops),
            'meta' => [
                'total' => $shops->total(),
                'per_page' => $shops->perPage(),
                'current_page' => $shops->currentPage(),
            ]
        ]);
    }

    public function shopDetails(int $id)
    {
        $shop = $this->shopService->getShopDetails($id);
        
        return response()->json([
            'success' => true,
            'data' => new ShopResource($shop)
        ]);
    }

    public function shopProducts(Request $request, int $id)
    {
        $products = $this->shopService->getShopProducts($id, $request->only(['search', 'part_category_id', 'per_page']));
        
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

    public function products(Request $request)
    {
        $products = $this->shopService->getProducts($request->only(['search', 'car_brand_id', 'part_category_id', 'min_price', 'max_price', 'in_stock', 'per_page']));
        
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

    public function productDetails(int $id)
    {
        $product = $this->shopService->getProductDetails($id);
        
        return response()->json([
            'success' => true,
            'data' => new ProductResource($product)
        ]);
    }
}