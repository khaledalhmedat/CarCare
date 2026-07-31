<?php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Repositories\Contracts\CustomerShopRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerShopService
{
    public function __construct(protected CustomerShopRepositoryInterface $repository) {}

    public function getShops(array $filters)
    {
        return $this->repository->getShops($filters);
    }

    public function getShopDetails(int $id)
    {
        $shop = $this->repository->findShop($id);
        if (!$shop || $shop->status !== 'approved') {
            throw new \Exception('المتجر غير موجود');
        }
        return $shop;
    }

    public function getShopProducts(int $shopId, array $filters)
    {
        $shop = $this->repository->findShop($shopId);
        if (!$shop || $shop->status !== 'approved') {
            throw new \Exception('المتجر غير موجود');
        }
        return $this->repository->getShopProducts($shopId, $filters);
    }

    public function getProducts(array $filters)
    {
        return $this->repository->getProducts($filters);
    }

    public function getProductDetails(int $id)
    {
        $product = $this->repository->findProduct($id);
        if (!$product || !$product->shop || $product->shop->status !== 'approved') {
            throw new \Exception('المنتج غير موجود');
        }
        return $product;
    }

    public function getCart(User $user)
    {
        return $this->repository->getCart($user);
    }

    public function addToCart(User $user, int $productId, int $quantity)
    {
        $product = $this->repository->findProduct($productId);
        if (!$product) {
            throw new \Exception('المنتج غير موجود');
        }

        if ($product->stock_quantity < $quantity) {
            throw new \Exception('الكمية المطلوبة غير متوفرة');
        }

        return $this->repository->addToCart($user, $productId, $quantity);
    }

    public function updateCart(User $user, int $cartId, int $quantity)
    {
        $cartItem = $user->cart()->find($cartId);
        if (!$cartItem) {
            throw new \Exception('العنصر غير موجود في السلة');
        }

        if ($cartItem->product->stock_quantity < $quantity) {
            throw new \Exception('الكمية المطلوبة غير متوفرة');
        }

        if ($quantity <= 0) {
            return $this->repository->removeFromCart($cartItem);
        }

        return $this->repository->updateCart($cartItem, $quantity);
    }

    public function removeFromCart(User $user, int $cartId)
    {
        $cartItem = $user->cart()->find($cartId);
        if (!$cartItem) {
            throw new \Exception('العنصر غير موجود في السلة');
        }

        return $this->repository->removeFromCart($cartItem);
    }

    public function createOrder(User $user, array $data): Order
    {
        try {
            DB::beginTransaction();

            $cartItems = $user->cart()->with('product')->get();
            if ($cartItems->isEmpty()) {
                throw new \Exception('السلة فارغة');
            }

            $totalPrice = $cartItems->sum(function($item) {
                return $item->product->final_price * $item->quantity;
            });

            $shopId = $cartItems->first()->product->shop_id;
            foreach ($cartItems as $item) {
                if ($item->product->shop_id !== $shopId) {
                    throw new \Exception('لا يمكن شراء منتجات من متاجر مختلفة في طلب واحد');
                }
            }

            $order = $this->repository->createOrder($user, [
                'user_id' => $user->id,
                'shop_id' => $shopId,
                'total_price' => $totalPrice,
                'customer_latitude' => $data['latitude'],
                'customer_longitude' => $data['longitude'],
                'delivery_address_note' => $data['address_note'] ?? null,
                'status' => 'pending',
            ]);

            foreach ($cartItems as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->product->final_price,
                ]);

                $item->product->decrement('stock_quantity', $item->quantity);
            }

            $this->repository->clearCart($user);

            DB::commit();
            return $order->load(['shop', 'items.product']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getUserOrders(User $user, ?string $status = null)
    {
        return $this->repository->getUserOrders($user, $status);
    }

    public function getOrderDetails(int $id, User $user): Order
    {
        $order = $this->repository->findOrder($id);
        if (!$order || $order->user_id !== $user->id) {
            throw new \Exception('الطلب غير موجود');
        }
        return $order;
    }

    public function cancelOrder(int $id, User $user, string $reason): bool
    {
        $order = $this->getOrderDetails($id, $user);

        if (!$order->canCancel()) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }

        return $this->repository->cancelOrder($order, $reason);
    }

    
public function getDeliveryTracking(User $user, int $orderId)
{
    $order = Order::with(['deliveryTrackingPoints', 'shop'])
        ->where('user_id', $user->id)
        ->find($orderId);
    
    if (!$order) {
        throw new \Exception('الطلب غير موجود');
    }

    if (!in_array($order->status, ['out_for_delivery', 'delivered'])) {
        throw new \Exception('الطلب لم يخرج للتوصيل بعد');
    }

    $points = $order->deliveryTrackingPoints()->orderBy('created_at', 'asc')->get();
    $lastLocation = $points->last();

    return [
        'order_id' => $order->id,
        'order_status' => $order->status,
        'tracking_points' => $points->map(fn($p) => [
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'timestamp' => $p->created_at->toDateTimeString(),
        ]),
        'last_location' => $lastLocation ? [
            'latitude' => $lastLocation->latitude,
            'longitude' => $lastLocation->longitude,
            'timestamp' => $lastLocation->created_at->toDateTimeString(),
        ] : null,
    ];
}
}