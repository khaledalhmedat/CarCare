<?php

namespace App\Repositories\Contracts;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

interface ShopRepositoryInterface
{
    public function findByUser(User $user): ?Shop;

    public function createForUser(User $user, array $data): Shop;

    public function update(Shop $shop, array $data): bool;

    public function findProduct(int $productId): ?Product;

    public function createProduct(Shop $shop, array $data): Product;

    public function updateProduct(Product $product, array $data): bool;

    public function deleteProduct(Product $product): bool;

    public function getShopProducts(Shop $shop);

    public function getShopOrders(Shop $shop, ?string $status = null);

    public function findShopOrder(Shop $shop, int $orderId): ?Order;

    public function updateOrderStatus(Order $order, string $status): bool;

    public function rejectOrder(Order $order, string $reason): bool;

    public function saveDeliveryLocation(Order $order, Shop $shop, float $lat, float $lng);

    public function getDeliveryTrackingPoints(Order $order);
}
