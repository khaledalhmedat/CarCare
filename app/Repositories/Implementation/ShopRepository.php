<?php

namespace App\Repositories\Implementation;

use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Repositories\Contracts\ShopRepositoryInterface;

class ShopRepository implements ShopRepositoryInterface
{
    public function __construct(protected Shop $model) {}

    public function findByUser(User $user): ?Shop
    {
        return $this->model->where('user_id', $user->id)->with(['businessTypes', 'carBrands', 'partCategories'])->first();
    }

    public function createForUser(User $user, array $data): Shop
    {
        $data['user_id'] = $user->id;

        return $this->model->create($data);
    }

    public function update(Shop $shop, array $data): bool
    {
        return $shop->update($data);
    }

    public function findProduct(int $productId): ?Product
    {
        return Product::with(['images', 'carBrand', 'partCategory'])->find($productId);
    }

    public function createProduct(Shop $shop, array $data): Product
    {
        return $shop->products()->create($data);
    }

    public function updateProduct(Product $product, array $data): bool
    {
        return $product->update($data);
    }

    public function deleteProduct(Product $product): bool
    {
        return $product->delete();
    }

    public function getShopProducts(Shop $shop)
    {
        return $shop->products()->with(['images', 'carBrand', 'partCategory'])->latest()->paginate(15);
    }

    public function getShopOrders(Shop $shop, ?string $status = null)
    {
        $query = $shop->orders()->with(['user', 'items.product'])->latest();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate(15);
    }

    public function findShopOrder(Shop $shop, int $orderId): ?Order
    {
        return $shop->orders()->with(['user', 'items.product'])->find($orderId);
    }

    public function updateOrderStatus(Order $order, string $status): bool
    {
        return $order->update(['status' => $status]);
    }

    public function rejectOrder(Order $order, string $reason): bool
    {
        return $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }

    public function saveDeliveryLocation(Order $order, Shop $shop, float $lat, float $lng)
    {
        return $order->deliveryTrackingPoints()->create([
            'shop_id' => $shop->id,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    public function getDeliveryTrackingPoints(Order $order)
    {
        return $order->deliveryTrackingPoints()->orderBy('created_at', 'asc')->get();
    }
}
