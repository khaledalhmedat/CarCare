<?php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Shop;
use App\Repositories\Contracts\CustomerShopRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerShopService
{
    public function __construct(
        protected CustomerShopRepositoryInterface $repository,
        protected NotificationService $notifications
    ) {}

    private function resolveShopOwnerUser(?int $shopId): ?User
    {
        return $shopId ? Shop::find($shopId)?->user : null;
    }

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

            // الخطوة 1: قفل صفوف سلة هذا المستخدم فقط، بترتيب ثابت — المصدر الوحيد الموثوق لمحتوى السلة
            $cartItems = Cart::where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw new \Exception('السلة فارغة');
            }

            // الخطوة 2: قفل صفوف المنتجات المعنية بترتيب ثابت (IDs فريدة ومرتبة تصاعدياً)
            $productIds = $cartItems->pluck('product_id')->unique()->sort()->values();

            $products = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // الخطوة 3: إعادة التحقق من كل القواعد اعتماداً على الصفوف المقفولة فقط
            $shopId = null;
            $requiredQuantities = [];

            foreach ($cartItems as $item) {
                $product = $products->get($item->product_id);

                if (!$product) {
                    throw new \Exception('المنتج غير موجود');
                }

                if ($shopId === null) {
                    $shopId = $product->shop_id;
                } elseif ($product->shop_id !== $shopId) {
                    throw new \Exception('لا يمكن شراء منتجات من متاجر مختلفة في طلب واحد');
                }

                $requiredQuantities[$item->product_id] = ($requiredQuantities[$item->product_id] ?? 0) + $item->quantity;
            }

            foreach ($requiredQuantities as $productId => $quantity) {
                if ($products->get($productId)->stock_quantity < $quantity) {
                    throw new \Exception('الكمية المطلوبة غير متوفرة');
                }
            }

            // الخطوة 4: السعر يُحسب بالكامل من المنتجات المقفولة، لا من الطلب
            $totalPrice = $cartItems->sum(function ($item) use ($products) {
                return $products->get($item->product_id)->final_price * $item->quantity;
            });

            // الخطوة 5: إنشاء الطلب والعناصر، ثم إنقاص المخزون بعد التأكد من كفايته بالكامل
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
                    'price' => $products->get($item->product_id)->final_price,
                ]);
            }

            foreach ($requiredQuantities as $productId => $quantity) {
                $products->get($productId)->decrement('stock_quantity', $quantity);
            }

            // الخطوة 6: تفريغ سلة هذا المستخدم فقط، داخل المعاملة نفسها
            $this->repository->clearCart($user);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $order = $order->load(['shop', 'items.product']);

        $shopOwner = $this->resolveShopOwnerUser($shopId);
        if ($shopOwner && $shopOwner->id !== $user->id) {
            $this->notifications->notifyUser(
                $shopOwner,
                'spare_parts_order_received',
                'طلب قطع غيار جديد',
                'تم إنشاء طلب جديد في متجرك ويحتاج إلى المراجعة',
                [
                    'entity_type' => 'spare_parts_order',
                    'entity_id' => $order->id,
                    'action' => 'open_details',
                    'status' => 'pending',
                    'shop_id' => $shopId,
                    'total_price' => $order->total_price,
                ]
            );
        }

        return $order;
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
        $result = DB::transaction(function () use ($id, $user, $reason) {
            $order = Order::where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new \Exception('الطلب غير موجود');
            }

            if (!$order->canCancel()) {
                throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
            }

            // ترتيب الأقفال: Order ثم OrderItems ثم Products
            $orderItems = OrderItem::where('order_id', $order->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $productIds = $orderItems->pluck('product_id')->unique()->sort()->values();

            $products = Product::whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $quantitiesToRestore = [];
            foreach ($orderItems as $item) {
                $quantitiesToRestore[$item->product_id] = ($quantitiesToRestore[$item->product_id] ?? 0) + $item->quantity;
            }

            foreach ($quantitiesToRestore as $productId => $quantity) {
                $product = $products->get($productId);
                if ($product) {
                    $product->increment('stock_quantity', $quantity);
                }
            }

            $cancelled = $this->repository->cancelOrder($order, $reason);

            return [
                'cancelled' => $cancelled,
                'order_id' => $order->id,
                'shop_id' => $order->shop_id,
            ];
        });

        if ($result['cancelled']) {
            $shopOwner = $this->resolveShopOwnerUser($result['shop_id']);
            if ($shopOwner && $shopOwner->id !== $user->id) {
                $this->notifications->notifyUser(
                    $shopOwner,
                    'spare_parts_order_cancelled_by_customer',
                    'تم إلغاء طلب قطع الغيار',
                    'قام العميل بإلغاء طلب قطع الغيار',
                    [
                        'entity_type' => 'spare_parts_order',
                        'entity_id' => $result['order_id'],
                        'action' => 'open_details',
                        'status' => 'cancelled',
                        'shop_id' => $result['shop_id'],
                        'reason' => $reason,
                    ]
                );
            }
        }

        return $result['cancelled'];
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