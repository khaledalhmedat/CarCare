<?php

namespace App\Repositories\Implementation;

use App\Models\Shop;
use App\Models\Product;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Contracts\CustomerShopRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CustomerShopRepository implements CustomerShopRepositoryInterface
{
    public function __construct(
        protected Shop $shopModel,
        protected Product $productModel,
        protected Cart $cartModel,
        protected Order $orderModel
    ) {}

    // ========== Shops ==========
    public function getShops(array $filters = []): LengthAwarePaginator
    {
        $query = $this->shopModel->where('is_active', true)
            ->where('status', 'approved')
            ->with(['businessTypes', 'carBrands', 'partCategories', 'user']);

        if (isset($filters['city'])) {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }

        if (isset($filters['business_type_id'])) {
            $query->whereHas('businessTypes', function($q) use ($filters) {
                $q->where('business_type_id', $filters['business_type_id']);
            });
        }

        if (isset($filters['car_brand_id'])) {
            $query->whereHas('carBrands', function($q) use ($filters) {
                $q->where('car_brand_id', $filters['car_brand_id']);
            });
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    public function findShop(int $id): ?Shop
    {
        return $this->shopModel->with(['businessTypes', 'carBrands', 'partCategories', 'user', 'products'])->find($id);
    }

    public function getShopProducts(int $shopId, array $filters = []): LengthAwarePaginator
    {
        $query = $this->productModel->where('shop_id', $shopId)
            ->with(['images', 'carBrand', 'partCategory']);

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['part_category_id'])) {
            $query->where('part_category_id', $filters['part_category_id']);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    // ========== Products ==========
    public function getProducts(array $filters = []): LengthAwarePaginator
    {
        $query = $this->productModel->with(['shop', 'images', 'carBrand', 'partCategory'])
            ->whereHas('shop', function($q) {
                $q->where('is_active', true)->where('status', 'approved');
            });

        if (isset($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['car_brand_id'])) {
            $query->where('car_brand_id', $filters['car_brand_id']);
        }

        if (isset($filters['part_category_id'])) {
            $query->where('part_category_id', $filters['part_category_id']);
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $query->where('stock_quantity', '>', 0);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    public function findProduct(int $id): ?Product
    {
        return $this->productModel->with(['shop', 'images', 'carBrand', 'partCategory'])->find($id);
    }

    // ========== Cart ==========
    public function getCart(User $user)
    {
        return $user->cart()->with('product.images')->get();
    }

    public function addToCart(User $user, int $productId, int $quantity): Cart
    {
        $existing = $user->cart()->where('product_id', $productId)->first();

        if ($existing) {
            $existing->update(['quantity' => $existing->quantity + $quantity]);
            return $existing->fresh();
        }

        return $user->cart()->create([
            'product_id' => $productId,
            'quantity' => $quantity,
        ]);
    }

    public function updateCart(Cart $cart, int $quantity): bool
    {
        return $cart->update(['quantity' => $quantity]);
    }

    public function removeFromCart(Cart $cart): bool
    {
        return $cart->delete();
    }

    public function clearCart(User $user): bool
    {
        return $user->cart()->delete();
    }

    // ========== Orders ==========
    public function createOrder(User $user, array $data): Order
    {
        return $this->orderModel->create($data);
    }

    public function getUserOrders(User $user, ?string $status = null): LengthAwarePaginator
    {
        $query = $user->orders()->with(['shop', 'items.product'])->latest();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate(15);
    }

    public function findOrder(int $id): ?Order
    {
        return $this->orderModel->with(['shop', 'items.product', 'user'])->find($id);
    }

    public function cancelOrder(Order $order, string $reason): bool
    {
        return $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }
}