<?php

namespace App\Services;

use App\Models\User;
use App\Models\Shop;
use App\Models\Product;
use App\Models\Order;
use App\Models\Role;
use App\Repositories\Contracts\ShopRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShopService
{
    public function __construct(protected ShopRepositoryInterface $repository) {}

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
                $shop = $this->repository->createForUser($user, $data);
            }

            $role = Role::where('slug', 'shop-owner')->first();
            if ($role && !$user->hasRole('shop-owner')) {
                $user->roles()->attach($role->id);
            }

            // تحديث العلاقات
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

            // رفع الصور
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

            // رفع صور جديدة
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

        // حذف الصور
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

    /**
 * عرض الطلبيات الواردة للمتجر
 */
public function getShopOrders(User $user, ?string $status = null)
{
    $shop = $this->repository->findByUser($user);
    if (!$shop) {
        throw new \Exception('لم تقم بإدخال معلومات متجرك بعد');
    }
    return $this->repository->getShopOrders($shop, $status);
}

/**
 * عرض تفاصيل طلبية واردة
 */
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

/**
 * قبول طلبية
 */
public function acceptOrder(User $user, int $orderId): Order
{
    $order = $this->getShopOrder($user, $orderId);

    if ($order->status !== 'pending') {
        throw new \Exception('لا يمكن قبول هذا الطلب حالياً');
    }

    $this->repository->updateOrderStatus($order, 'accepted');
    return $order->fresh();
}

/**
 * رفض طلبية
 */
public function rejectOrder(User $user, int $orderId, string $reason): Order
{
    $order = $this->getShopOrder($user, $orderId);

    if ($order->status !== 'pending') {
        throw new \Exception('لا يمكن رفض هذا الطلب حالياً');
    }

    $this->repository->rejectOrder($order, $reason);
    return $order->fresh();
}

/**
 * تحديث حالة الطلبية (processing, out_for_delivery, delivered)
 */
public function updateOrderStatus(User $user, int $orderId, string $status, ?string $notes = null): Order
{
    $order = $this->getShopOrder($user, $orderId);

    $allowedStatuses = ['processing', 'out_for_delivery', 'delivered'];
    if (!in_array($status, $allowedStatuses)) {
        throw new \Exception('الحالة غير صحيحة');
    }

    // التحقق من التسلسل
    if ($status === 'processing' && $order->status !== 'accepted') {
        throw new \Exception('لا يمكن تجهيز طلب لم يتم قبوله بعد');
    }
    if ($status === 'out_for_delivery' && $order->status !== 'processing') {
        throw new \Exception('لا يمكن بدء التوصيل قبل تجهيز الطلب');
    }
    if ($status === 'delivered' && $order->status !== 'out_for_delivery') {
        throw new \Exception('لا يمكن تأكيد التوصيل قبل بدء التوصيل');
    }

    $this->repository->updateOrderStatus($order, $status);
    return $order->fresh();
}

/**
 * مشاركة موقع المندوب
 */
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

    // ✅ بث الموقع للمستخدم عبر WebSocket
    // broadcast(new DeliveryLocationUpdated($order, $lat, $lng));

    return $point;
}



/**
 * تتبع موقع التوصيل (للمستخدم)
 */
/**
 * تتبع موقع التوصيل (للمستخدم)
 */
public function getDeliveryTracking(User $user, int $orderId)
{
    // ✅ استخدم الدالة مباشرة بدل getOrderDetails
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