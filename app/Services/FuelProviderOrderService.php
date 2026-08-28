<?php

namespace App\Services;

use App\Models\User;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use App\Events\FuelOrderAccepted;
use App\Events\FuelOrderStatusUpdated;
use App\Events\FuelOrderCancelled;
use App\Events\FuelProviderLocationUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Helpers\HaversineTrait;
use App\Models\FuelOrderTrackingPoint;
use App\Exceptions\ServiceAcceptanceException;




class FuelProviderOrderService
{
    // Kept in sync with the 30km radius used by getAvailableOrders() below.
    private const MAX_SERVICE_DISTANCE_KM = 30;

    public function __construct(
        protected FuelOrderRepositoryInterface $repository,
        protected NotificationService $notifications,
        protected FuelOrderService $fuelOrderService
    ) {}

    use HaversineTrait;



    public function getAvailableOrders(?FuelProvider $provider)
    {
        $empty = FuelOrder::whereRaw('1 = 0')->paginate(15);

        // مزود الوقود يجب أن يكون موجوداً ومعتمداً ومتاحاً حتى يرى الطلبات
        if (!$provider || $provider->status !== 'approved' || !$provider->is_available) {
            return $empty;
        }

        // إذا لم يحدد المزود أنواع الوقود التي يوفرها، فلا يرى أي طلب
        $fuelTypes = $provider->fuel_types ?? [];
        if (empty($fuelTypes)) {
            return $empty;
        }

        $city = $provider->city;
        $lat = $provider->latitude;
        $lng = $provider->longitude;

        $query = FuelOrder::where('status', 'pending')
            ->whereIn('fuel_type', $fuelTypes)
            ->with(['user', 'vehicle'])
            ->latest();

        if ($lat && $lng) {
            $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(delivery_latitude)) * cos(radians(delivery_longitude) - radians(?)) + sin(radians(?)) * sin(radians(delivery_latitude))))";

            // الطلب يظهر إذا كان قريباً (إحداثيات موجودة ضمن MAX_SERVICE_DISTANCE_KM)
            // أو إذا لم تُحدَّد إحداثياته أصلاً وكان في نفس مدينة المزود (لا يمكن حساب
            // المسافة بدون إحداثيات). طلب له إحداثيات لكنه أبعد من الحد لا يُطابق بالمدينة
            // بعد الآن — كان هذا هو الثغرة التي تُظهر طلبات أبعد من 30 كم في القائمة.
            $query->where(function ($group) use ($lat, $lng, $city, $haversine) {
                $group->where(function ($near) use ($lat, $lng, $haversine) {
                    $near->whereNotNull('delivery_latitude')
                        ->whereNotNull('delivery_longitude')
                        ->whereRaw("{$haversine} <= ?", [$lat, $lng, $lat, self::MAX_SERVICE_DISTANCE_KM]);
                });

                if ($city) {
                    $group->orWhere(function ($noCoords) use ($city) {
                        $noCoords->where(function ($missing) {
                            $missing->whereNull('delivery_latitude')
                                ->orWhereNull('delivery_longitude');
                        })->where('city', $city);
                    });
                }
            });
        } elseif ($city) {
            $query->where('city', $city);
        }

        return $query->paginate(15);
    }

    public function getOrderDetails(int $orderId): FuelOrder
    {
        $order = $this->repository->find($orderId);

        if (!$order) {
            throw new \Exception('الطلب غير موجود');
        }

        return $order;
    }


    public function acceptOrder(User $provider, int $orderId, array $data): FuelOrder
    {
        $fuelProvider = $provider->fuelProvider;

        if (!$fuelProvider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        if ($fuelProvider->status !== 'approved') {
            throw new \Exception('حسابك كمزود وقود لم يتم اعتماده بعد من الإدارة');
        }

        if (!$fuelProvider->is_available) {
            throw new \Exception('يجب تفعيل حالة التوفر لقبول الطلبات');
        }

        // Pre-acceptance checks: read-only, done before the row lock below so a
        // provider that's out of range or doesn't carry this fuel type never
        // mutates the order. Loaded outside the transaction on purpose — this
        // only reads fields that don't change (fuel_type, delivery coordinates).
        $orderForChecks = FuelOrder::find($orderId);
        if ($orderForChecks) {
            $this->assertWithinServiceRange($fuelProvider, $orderForChecks);
            $this->assertFuelTypeSupported($fuelProvider, $orderForChecks);
        }

        // Lock the row so two providers cannot claim the same pending order:
        // whoever acquires the lock first accepts; the other re-reads 'accepted' and is rejected.
        $order = DB::transaction(function () use ($fuelProvider, $orderId, $data) {
            $order = FuelOrder::whereKey($orderId)->lockForUpdate()->first();

            if (!$order) {
                throw new \Exception('الطلب غير موجود');
            }

            if ($order->status !== 'pending') {
                throw new \Exception('هذا الطلب غير متاح للقبول');
            }

            $prices = $fuelProvider->prices ?? [];
            $pricePerLiter = $prices[$order->fuel_type] ?? 2.5;
            $totalPrice = $order->amount * $pricePerLiter;

            $order->update([
                'fuel_provider_id' => $fuelProvider->id,
                'total_price' => $totalPrice,
                'status' => 'accepted',
                'accepted_at' => now(),
                'estimated_arrival_minutes' => $data['estimated_arrival_minutes'] ?? null,
                'provider_notes' => $data['notes'] ?? null,
            ]);

            return $order;
        });

        $order = $order->fresh(['user', 'vehicle']);

        // broadcast after commit — a broadcast failure must not undo the accept
        try {
            broadcast(new FuelOrderAccepted($order, $fuelProvider));
        } catch (\Throwable $e) {
            Log::warning('fuel.accept.broadcast_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        $customer = $order->user;
        if ($customer && $customer->id !== $provider->id) {
            $this->notifications->notifyUser(
                $customer,
                'fuel_order_accepted',
                'تم قبول طلب الوقود',
                'تم قبول طلب الوقود الخاص بك، والمزود في طريقه إليك',
                [
                    'entity_type' => 'fuel_order',
                    'entity_id' => $order->id,
                    'action' => 'open_details',
                    'status' => 'accepted',
                    'fuel_provider_id' => $fuelProvider->id,
                    'estimated_arrival_minutes' => $order->estimated_arrival_minutes,
                ]
            );
        }

        return $order;
    }

    /**
     * Mirrors the distance rule already enforced in getAvailableOrders() above,
     * but as a hard gate on acceptance instead of a listing filter.
     */
    private function assertWithinServiceRange(FuelProvider $fuelProvider, FuelOrder $order): void
    {
        if (!$fuelProvider->latitude || !$fuelProvider->longitude) {
            throw new ServiceAcceptanceException(
                'تعذر التحقق من نطاق الخدمة لأن موقع مقدم الخدمة غير محدد.',
                'PROVIDER_LOCATION_REQUIRED'
            );
        }

        if (!$order->delivery_latitude || !$order->delivery_longitude) {
            throw new ServiceAcceptanceException(
                'تعذر التحقق من نطاق الخدمة لأن موقع تسليم الطلب غير محدد.',
                'DELIVERY_LOCATION_REQUIRED'
            );
        }

        $distance = $this->calculateDistance(
            (float) $fuelProvider->latitude,
            (float) $fuelProvider->longitude,
            (float) $order->delivery_latitude,
            (float) $order->delivery_longitude
        );

        if ($distance > self::MAX_SERVICE_DISTANCE_KM) {
            throw new ServiceAcceptanceException(
                'الطلب خارج نطاق التغطية. لا يمكن قبول الطلبات التي تبعد أكثر من 30 كم عن موقعك.',
                'OUT_OF_SERVICE_RANGE',
                [
                    'max_distance_km' => self::MAX_SERVICE_DISTANCE_KM,
                    'distance_km' => round($distance, 2),
                ]
            );
        }
    }

    private function assertFuelTypeSupported(FuelProvider $fuelProvider, FuelOrder $order): void
    {
        $fuelTypes = $fuelProvider->fuel_types ?? [];

        if (!in_array($order->fuel_type, $fuelTypes, true)) {
            throw new ServiceAcceptanceException(
                'نوع الوقود المطلوب غير متاح لدى هذا المزود.',
                'UNSUPPORTED_FUEL_TYPE'
            );
        }
    }


    public function shareLocation(User $provider, int $orderId, array $data): void
    {
        $fuelProvider = $provider->fuelProvider;

        if (!$fuelProvider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        $order = $this->repository->find($orderId);

        if (!$order || $order->fuel_provider_id !== $fuelProvider->id) {
            throw new \Exception('الطلب غير مرتبط بك');
        }

        if (!in_array($order->status, ['accepted', 'in_progress'])) {
            throw new \Exception('لا يمكن مشاركة الموقع في هذه المرحلة');
        }

        FuelOrderTrackingPoint::create([
            'fuel_order_id' => $orderId,
            'fuel_provider_id' => $fuelProvider->id,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);

        $fuelProvider->update([
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);

        try {
            broadcast(new FuelProviderLocationUpdated(
                $orderId,
                $fuelProvider->id,
                $data['latitude'],
                $data['longitude']
            ));
        } catch (\Throwable $e) {
            Log::warning('fuel.location.broadcast_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }
    }


    public function updateOrderStatus(User $provider, int $orderId, string $status): FuelOrder
    {
        $fuelProvider = $provider->fuelProvider;

        if (!$fuelProvider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        $allowedFrom = ['in_progress' => ['accepted'], 'completed' => ['accepted', 'in_progress']];

        $order = DB::transaction(function () use ($fuelProvider, $orderId, $status, $allowedFrom) {
            $order = FuelOrder::where('id', $orderId)
                ->where('fuel_provider_id', $fuelProvider->id)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new \Exception('الطلب غير موجود أو لا يخصك');
            }

            if (!isset($allowedFrom[$status]) || !in_array($order->status, $allowedFrom[$status], true)) {
                throw new \Exception('لا يمكن تحديث حالة الطلب في وضعه الحالي');
            }

            $data = ['status' => $status];

            if ($status === 'in_progress') {
                $data['started_at'] = now();
            }

            if ($status === 'completed') {
                $data['completed_at'] = now();

                \App\Models\FuelLog::create([
                    'vehicle_id' => $order->vehicle_id,
                    'fuel_order_id' => $order->id,
                    'amount' => $order->amount,
                    'fuel_type' => $order->fuel_type,
                    'fuel_provider_id' => $fuelProvider->id,
                    'cost' => $order->total_price,
                    'km_at_fill' => 0,
                ]);
            }

            $order->update($data);

            return $order->fresh();
        });

        try {
            broadcast(new FuelOrderStatusUpdated($order));
        } catch (\Throwable $e) {
            Log::warning('fuel.status.broadcast_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        $customer = $order->user;
        if ($customer && $customer->id !== $provider->id) {
            if ($status === 'in_progress') {
                $this->notifications->notifyUser(
                    $customer,
                    'fuel_order_in_progress',
                    'جاري توصيل الوقود',
                    'المزود في طريقه لتوصيل طلب الوقود الخاص بك',
                    [
                        'entity_type' => 'fuel_order',
                        'entity_id' => $order->id,
                        'action' => 'open_details',
                        'status' => 'in_progress',
                        'fuel_provider_id' => $fuelProvider->id,
                    ]
                );
            } elseif ($status === 'completed') {
                $this->notifications->notifyUser(
                    $customer,
                    'fuel_order_completed',
                    'تم توصيل الوقود',
                    'تم توصيل طلب الوقود بنجاح',
                    [
                        'entity_type' => 'fuel_order',
                        'entity_id' => $order->id,
                        'action' => 'open_details',
                        'status' => 'completed',
                        'fuel_provider_id' => $fuelProvider->id,
                    ]
                );
            }
        }

        return $order;
    }


    public function cancelOrder(User $provider, int $orderId, string $reason): FuelOrder
    {
        $fuelProvider = $provider->fuelProvider;

        if (!$fuelProvider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        $order = $this->repository->find($orderId);

        if (!$order || $order->fuel_provider_id !== $fuelProvider->id) {
            throw new \Exception('الطلب غير موجود أو لا يخصك');
        }

        if (!in_array($order->status, ['accepted', 'in_progress'])) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }

        $order->update([
            'status' => 'pending',
            'fuel_provider_id' => null,
            'accepted_at' => null,
            'cancellation_reason' => $reason,
        ]);

        $order = $order->fresh(['user', 'vehicle']);

        try {
            broadcast(new FuelOrderCancelled($order, $fuelProvider, $reason));
        } catch (\Throwable $e) {
            Log::warning('fuel.provider_cancel.broadcast_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        // يعيد إعلان الطلب لمزودي الوقود المؤهلين بنفس منطق البحث المستخدم عند الإنشاء، مع استبعاد المزود الذي ألغى
        $this->fuelOrderService->reannounceOrder($order, $fuelProvider->id);

        $customer = $order->user;
        if ($customer && $customer->id !== $provider->id) {
            $this->notifications->notifyUser(
                $customer,
                'fuel_order_reopened_after_provider_cancel',
                'تمت إعادة فتح طلب الوقود',
                'ألغى المزود الطلب، وتمت إعادته للبحث عن مزود آخر',
                [
                    'entity_type' => 'fuel_order',
                    'entity_id' => $order->id,
                    'action' => 'open_details',
                    'status' => 'pending',
                    'fuel_provider_id' => $fuelProvider->id,
                    'reason' => $reason,
                ]
            );
        }

        return $order;
    }


    public function getMyOrders(User $provider, ?string $status = null)
    {
        $fuelProvider = $provider->fuelProvider;

        if (!$fuelProvider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        $query = FuelOrder::where('fuel_provider_id', $fuelProvider->id)
            ->with(['user', 'vehicle']);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->latest()->paginate(15);
    }
}
