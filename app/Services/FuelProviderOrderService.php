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
use App\Events\NewEmergencyFuelOrder;
use App\Helpers\HaversineTrait;
use App\Models\FuelOrderTrackingPoint;




class FuelProviderOrderService
{
    public function __construct(protected FuelOrderRepositoryInterface $repository) {}

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

            // الطلب يظهر إذا كان قريباً (إحداثيات موجودة ضمن 30 كم) أو في نفس مدينة المزود
            // (الطلبات بدون إحداثيات لا تُستبعد بصمت، بل تُطابق بالمدينة)
            $query->where(function ($group) use ($lat, $lng, $city, $haversine) {
                $group->where(function ($near) use ($lat, $lng, $haversine) {
                    $near->whereNotNull('delivery_latitude')
                        ->whereNotNull('delivery_longitude')
                        ->whereRaw("{$haversine} <= 30", [$lat, $lng, $lat]);
                });

                if ($city) {
                    $group->orWhere('city', $city);
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

        // broadcast after commit — a broadcast failure must not undo the accept
        try {
            broadcast(new FuelOrderAccepted($order, $fuelProvider));
        } catch (\Throwable $e) {
            Log::warning('fuel.accept.broadcast_failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        return $order->fresh(['user', 'vehicle']);
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

        broadcast(new FuelProviderLocationUpdated(
            $orderId,
            $fuelProvider->id,
            $data['latitude'],
            $data['longitude']
        ));
    }


    public function updateOrderStatus(User $provider, int $orderId, string $status): FuelOrder
    {
        $fuelProvider = $provider->fuelProvider;

        if (!$fuelProvider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        $order = $this->repository->find($orderId);

        if (!$order || $order->fuel_provider_id !== $fuelProvider->id) {
            throw new \Exception('الطلب غير موجود أو لا يخصك');
        }

        // only allow forward transitions; blocks re-completing (which would duplicate the FuelLog)
        // and blocks updating a cancelled/completed order
        $allowedFrom = ['in_progress' => ['accepted'], 'completed' => ['accepted', 'in_progress']];
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

        broadcast(new FuelOrderStatusUpdated($order));

        return $order->fresh();
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

        broadcast(new FuelOrderCancelled($order, $fuelProvider, $reason));

        broadcast(new NewEmergencyFuelOrder($order, null, null));

        return $order->fresh();
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
