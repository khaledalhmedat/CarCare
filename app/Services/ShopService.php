<?php

namespace App\Services;

use App\Models\User;
use App\Models\Shop;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShopService
{
    public function __construct(
        protected ShopRepositoryInterface $repository,
        protected NotificationService $notifications
    ) {}

    public function getProfile(User $user): ?Shop
    {
        return $this->repository->findByUser($user);
    }

    public function createOrUpdateShop(User $user, array $data): Shop
    {
        try {
            DB::beginTransaction();

            $shop = $this->repository->findByUser($user);

            if ($shop) {
                $this->repository->update($shop, $data);
            } else {
                $data['status'] = 'pending';
                $shop = $this->repository->createForUser($user, $data);
            }

            $role = Role::where('slug', 'shop-owner')->first();
            if ($role && !$user->hasRole('shop-owner')) {
                $user->roles()->attach($role->id);
            }

            if (isset($data['business_types'])) {
                $shop->businessTypes()->sync($data['business_types']);
            }
            if (isset($data['car_brands'])) {
                $shop->carBrands()->sync($data['car_brands']);
            }
            if (isset($data['part_categories'])) {
                $shop->partCategories()->sync($data['part_categories']);
            }

            DB::commit();
            return $shop->fresh(['businessTypes', 'carBrands', 'partCategories']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createProduct(User $user, array $data, $imageFiles = null): Product
    {
        try {
            DB::beginTransaction();

            $shop = $this->repository->findByUser($user);
            if (!$shop) {
                throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
            }

            $product = $this->repository->createProduct($shop, $data);

            if ($imageFiles) {
                foreach ($imageFiles as $index => $image) {
                    $path = $image->store('products', 'public');
                    $product->images()->create([
                        'image_path' => $path,
                        'is_primary' => $index === 0,
                    ]);
                }
            }

            DB::commit();
            return $product->load(['images', 'carBrand', 'partCategory']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateProduct(User $user, int $productId, array $data, $imageFiles = null): Product
    {
        try {
            DB::beginTransaction();

            $shop = $this->repository->findByUser($user);
            if (!$shop) {
                throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
            }

            $product = $this->repository->findProduct($productId);
            if (!$product || $product->shop_id !== $shop->id) {
                throw new \Exception('المنتج غير موجود أو لا يخصك');
            }

            $this->repository->updateProduct($product, $data);

            if ($imageFiles) {
                foreach ($imageFiles as $image) {
                    $path = $image->store('products', 'public');
                    $product->images()->create([
                        'image_path' => $path,
                        'is_primary' => false,
                    ]);
                }
            }

            DB::commit();
            return $product->fresh(['images', 'carBrand', 'partCategory']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteProduct(User $user, int $productId): bool
    {
        $shop = $this->repository->findByUser($user);
        if (!$shop) {
            throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
        }

        $product = $this->repository->findProduct($productId);
        if (!$product || $product->shop_id !== $shop->id) {
            throw new \Exception('المنتج غير موجود أو لا يخصك');
        }

        foreach ($product->images as $image) {
            Storage::disk('public')->delete($image->image_path);
            $image->delete();
        }

        return $this->repository->deleteProduct($product);
    }

    public function getShopProducts(User $user)
    {
        $shop = $this->repository->findByUser($user);
        if (!$shop) {
            throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
        }
        return $this->repository->getShopProducts($shop);
    }

    
public function getShopOrders(User $user, ?string $status = null)
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }
    return $this->repository->getShopOrders($shop, $status);
}

public function getShopOrder(User $user, int $orderId): Order
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }

    $order = $this->repository->findShopOrder($shop, $orderId);
    if (!$order) {
        throw new \Exception('الطلب غير موجود');
    }
    return $order;
}


public function acceptOrder(User $user, int $orderId): Order
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }
    if ($shop->status !== 'approved') {
        throw new \Exception('متجرك لم يتم اعتماده بعد من الإدارة');
    }

    $order = DB::transaction(function () use ($shop, $orderId) {
        $order = Order::where('id', $orderId)
            ->where('shop_id', $shop->id)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($order->status !== 'pending') {
            throw new \Exception('لا يمكن قبول هذا الطلب حالياً');
        }

        $this->repository->updateOrderStatus($order, 'accepted');

        return $order->fresh();
    });

    $customer = $order->user;
    if ($customer && $customer->id !== $user->id) {
        $this->notifications->notifyUser(
            $customer,
            'spare_parts_order_accepted',
            'تم قبول طلب قطع الغيار',
            'وافق المتجر على طلب قطع الغيار الخاص بك',
            [
                'entity_type' => 'spare_parts_order',
                'entity_id' => $order->id,
                'action' => 'open_details',
                'status' => 'accepted',
                'shop_id' => $shop->id,
            ]
        );
    }

    return $order;
}

public function rejectOrder(User $user, int $orderId, string $reason): Order
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }

    $order = DB::transaction(function () use ($shop, $orderId, $reason) {
        $order = Order::where('id', $orderId)
            ->where('shop_id', $shop->id)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($order->status !== 'pending') {
            throw new \Exception('لا يمكن رفض هذا الطلب حالياً');
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

        $this->repository->rejectOrder($order, $reason);

        return $order->fresh();
    });

    $customer = $order->user;
    if ($customer && $customer->id !== $user->id) {
        $this->notifications->notifyUser(
            $customer,
            'spare_parts_order_rejected',
            'تم رفض طلب قطع الغيار',
            'رفض المتجر طلب قطع الغيار الخاص بك',
            [
                'entity_type' => 'spare_parts_order',
                'entity_id' => $order->id,
                'action' => 'open_details',
                'status' => 'cancelled',
                'shop_id' => $shop->id,
                'reason' => $order->cancellation_reason,
            ]
        );
    }

    return $order;
}


public function updateOrderStatus(User $user, int $orderId, string $status, ?string $notes = null): Order
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }

    $allowedStatuses = ['processing', 'out_for_delivery', 'delivered'];
    if (!in_array($status, $allowedStatuses)) {
        throw new \Exception('الحالة غير صحيحة');
    }

    // كل حالة هدف لها حالة سابقة واحدة مسموحة فقط، والتحقق يتم على الصف المقفول أدناه
    $allowedFrom = [
        'processing' => 'accepted',
        'out_for_delivery' => 'processing',
        'delivered' => 'out_for_delivery',
    ];

    $errorMessages = [
        'processing' => 'لا يمكن تجهيز طلب لم يتم قبوله بعد',
        'out_for_delivery' => 'لا يمكن بدء التوصيل قبل تجهيز الطلب',
        'delivered' => 'لا يمكن تأكيد التوصيل قبل بدء التوصيل',
    ];

    $order = DB::transaction(function () use ($shop, $orderId, $status, $allowedFrom, $errorMessages) {
        $order = Order::where('id', $orderId)
            ->where('shop_id', $shop->id)
            ->lockForUpdate()
            ->first();

        if (!$order) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($order->status !== $allowedFrom[$status]) {
            throw new \Exception($errorMessages[$status]);
        }

        $this->repository->updateOrderStatus($order, $status);

        return $order->fresh();
    });

    $customer = $order->user;
    if ($customer && $customer->id !== $user->id) {
        $notificationsByStatus = [
            'processing' => ['spare_parts_order_processing', 'جاري تجهيز طلبك', 'بدأ المتجر بتجهيز طلب قطع الغيار الخاص بك'],
            'out_for_delivery' => ['spare_parts_order_out_for_delivery', 'طلبك في طريقه إليك', 'تم تجهيز طلب قطع الغيار وخرج للتوصيل'],
            'delivered' => ['spare_parts_order_delivered', 'تم تسليم طلبك', 'تم تسليم طلب قطع الغيار بنجاح'],
        ];
        [$type, $title, $body] = $notificationsByStatus[$status];

        $this->notifications->notifyUser(
            $customer,
            $type,
            $title,
            $body,
            [
                'entity_type' => 'spare_parts_order',
                'entity_id' => $order->id,
                'action' => 'open_details',
                'status' => $status,
                'shop_id' => $shop->id,
            ]
        );
    }

    return $order;
}


public function shareDeliveryLocation(User $user, int $orderId, float $lat, float $lng)
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }

    $order = $this->repository->findShopOrder($shop, $orderId);
    if (!$order) {
        throw new \Exception('الطلب غير موجود');
    }

    if ($order->status !== 'out_for_delivery') {
        throw new \Exception('لا يمكن مشاركة الموقع إلا بعد بدء التوصيل');
    }

    $point = $this->repository->saveDeliveryLocation($order, $shop, $lat, $lng);



    return $point;
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