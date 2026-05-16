<?php

namespace App\Services;

use App\Models\User;
use App\Models\FuelOrder;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use App\Events\FuelOrderAccepted;
use App\Events\FuelOrderStatusUpdated;
use App\Events\FuelOrderCancelled;
use App\Events\FuelProviderLocationUpdated;
use Illuminate\Support\Facades\DB;
use App\Events\NewEmergencyFuelOrder;
use App\Helpers\HaversineTrait;
use App\Models\FuelOrderTrackingPoint;




class FuelProviderOrderService
{
    public function __construct(protected FuelOrderRepositoryInterface $repository) {}

    use HaversineTrait;



    public function getAvailableOrders(?string $city = null, ?float $lat = null, ?float $lng = null)
    {
        $query = FuelOrder::where('status', 'pending')
            ->with(['user', 'vehicle'])
            ->latest();

        if ($city) {
            $query->where('city', $city);
        }

        if ($lat && $lng) {
            $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(delivery_latitude)) * cos(radians(delivery_longitude) - radians(?)) + sin(radians(?)) * sin(radians(delivery_latitude))))";

            $query->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
                ->having('distance', '<=', 30)
                ->orderBy('distance');
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

        $order = $this->repository->find($orderId);

        if (!$order) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($order->status !== 'pending') {
            throw new \Exception('هذا الطلب غير متاح للقبول');
        }

        $prices = $fuelProvider->prices ?? [];
        $pricePerLiter = $prices[$order->fuel_type] ?? 2.5;
        $totalPrice = $order->amount * $pricePerLiter;

        try {
            DB::beginTransaction();

            $order->update([
                'fuel_provider_id' => $fuelProvider->id,
                'total_price' => $totalPrice,
                'status' => 'accepted',
                'accepted_at' => now(),
                'estimated_arrival_minutes' => $data['estimated_arrival_minutes'] ?? null,
                'provider_notes' => $data['notes'] ?? null,
            ]);

            broadcast(new FuelOrderAccepted($order, $fuelProvider));

            DB::commit();

            return $order->fresh(['user', 'vehicle']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
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
