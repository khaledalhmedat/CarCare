<?php

namespace App\Services;

use App\Models\User;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\NewEmergencyFuelOrder;
use Illuminate\Support\Facades\Http;
use App\Helpers\HaversineTrait;




class FuelOrderService
{
    public function __construct(protected FuelOrderRepositoryInterface $repository) {}

    use HaversineTrait;

    public function getUserOrders(User $user, ?string $status = null)
    {
        return $this->repository->getUserOrders($user, $status);
    }

    public function getOrder(int $id, User $user): FuelOrder
    {
        $order = $this->repository->find($id);
        if (!$order || $order->user_id !== $user->id) {
            throw new \Exception('الطلب غير موجود أو لا تملك صلاحية الوصول إليه');
        }
        return $order;
    }

    public function createOrder(User $user, array $data): FuelOrder
    {
        try {
            DB::beginTransaction();
            $order = $this->repository->createForUser($user, $data);
            DB::commit();
            return $order->load(['vehicle']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function cancelOrder(int $id, User $user, string $reason): bool
    {
        $order = $this->getOrder($id, $user);
        if (!$order->canCancel()) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }
        return $this->repository->cancel($order, $reason);
    }

    public function assignProvider(int $orderId, int $providerId, User $provider): FuelOrder
    {
        $order = $this->repository->find($orderId);
        if (!$order || $order->status !== 'pending') {
            throw new \Exception('الطلب غير متاح للتخصيص');
        }
        $this->repository->assignProvider($order, $providerId);
        return $order->fresh();
    }

    public function updateStatus(int $orderId, string $status, User $provider): FuelOrder
    {
        $order = $this->repository->find($orderId);
        if (!$order || $order->fuel_provider_id !== $provider->fuelProvider?->id) {
            throw new \Exception('لا تملك صلاحية تحديث هذا الطلب');
        }
        $this->repository->updateStatus($order, $status);
        return $order->fresh();
    }


    public function createEmergencyOrder(User $user, array $data): FuelOrder
    {
        $vehicle = $user->vehicles()->find($data['vehicle_id']);
        if (!$vehicle) {
            throw new \Exception('المركبة غير موجودة');
        }

        $city = $data['city'] ?? $this->getCityFromCoordinates(
            $data['delivery_latitude'],
            $data['delivery_longitude']
        );

        $order = DB::transaction(function () use ($user, $data, $city) {
            return $this->repository->createForUser($user, [
                'vehicle_id' => $data['vehicle_id'],
                'fuel_type' => $data['fuel_type'],
                'amount' => $data['amount'],
                'delivery_latitude' => $data['delivery_latitude'],
                'delivery_longitude' => $data['delivery_longitude'],
                'delivery_address' => $data['delivery_address'] ?? $city,
                'city' => $city,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);
        });

        $this->notifyProviders($order, $city, $data);

        return $order->load(['vehicle']);
    }

    protected function notifyProviders(FuelOrder $order, ?string $city, array $data): void
    {
        try {
            $nearbyProviders = $this->getNearbyFuelProviders(
                $data['delivery_latitude'],
                $data['delivery_longitude'],
                30
            );

            $providersNotified = [];

            if ($nearbyProviders->isNotEmpty()) {
                foreach ($nearbyProviders as $provider) {
                    broadcast(new NewEmergencyFuelOrder($order, $provider, $provider->distance));
                    $providersNotified[] = $provider->id;
                }

                Log::info('Fuel order: ' . $nearbyProviders->count() . ' nearby providers notified', [
                    'order_id' => $order->id,
                    'providers' => $providersNotified
                ]);

                return;
            }

            $cityProviders = $this->getFuelProvidersByCity($city);

            if ($cityProviders->isNotEmpty()) {
                foreach ($cityProviders as $provider) {
                    broadcast(new NewEmergencyFuelOrder($order, $provider, null));
                    $providersNotified[] = $provider->id;
                }

                Log::info('Fuel order: ' . $cityProviders->count() . ' city providers notified', [
                    'order_id' => $order->id,
                    'city' => $city,
                    'providers' => $providersNotified
                ]);
            } else {
                Log::warning('Fuel order: no providers found (neither nearby nor in same city)', [
                    'order_id' => $order->id,
                    'city' => $city,
                    'lat' => $data['delivery_latitude'],
                    'lng' => $data['delivery_longitude']
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('fuel.emergency_order.broadcast_failed', [
                'fuel_order_id' => $order->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }


    protected function getNearbyFuelProviders(float $lat, float $lng, int $radiusInKm = 30)
    {
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        return FuelProvider::where('is_available', true)
            ->where('status', 'approved')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
            ->having('distance', '<=', $radiusInKm)
            ->orderBy('distance')
            ->get()
            ->map(function ($provider) {
                $provider->distance = round($provider->distance, 2);
                return $provider;
            });
    }

    protected function getFuelProvidersByCity(?string $city)
    {
        if (!$city) {
            return collect();
        }

        return FuelProvider::where('is_available', true)
            ->where('status', 'approved')
            ->where('city', $city)
            ->get()
            ->map(function ($provider) {
                $provider->distance = null;
                return $provider;
            });
    }


    private function getCityFromCoordinates(float $lat, float $lng): ?string
    {
        try {
            $response = Http::timeout(5)->get("https://nominatim.openstreetmap.org/reverse", [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'json',
                'addressdetails' => 1,
            ]);

            $data = $response->json();

            return $data['address']['city'] ??
                $data['address']['town'] ??
                $data['address']['village'] ??
                $data['address']['state'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
