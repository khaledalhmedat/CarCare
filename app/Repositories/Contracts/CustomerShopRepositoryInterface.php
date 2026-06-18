<?php

namespace App\Repositories\Contracts;

use App\Models\Shop;
use App\Models\Product;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface CustomerShopRepositoryInterface
{
    // Shops
    public function getShops(array $filters = []): LengthAwarePaginator;
    public function findShop(int $id): ?Shop;
    public function getShopProducts(int $shopId, array $filters = []): LengthAwarePaginator;
    
    // Products
    public function getProducts(array $filters = []): LengthAwarePaginator;
    public function findProduct(int $id): ?Product;
    
    // Cart
    public function getCart(User $user);
    public function addToCart(User $user, int $productId, int $quantity): Cart;
    public function updateCart(Cart $cart, int $quantity): bool;
    public function removeFromCart(Cart $cart): bool;
    public function clearCart(User $user): bool;
    
    // Orders
    public function createOrder(User $user, array $data): Order;
    public function getUserOrders(User $user, ?string $status = null): LengthAwarePaginator;
    public function findOrder(int $id): ?Order;
    public function cancelOrder(Order $order, string $reason): bool;
}