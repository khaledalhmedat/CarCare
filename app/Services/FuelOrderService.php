<?php

namespace App\Services;

use App\Models\User;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\DispatchNotificationRecipient;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Events\NewEmergencyFuelOrder;
use Illuminate\Support\Facades\Http;
use App\Helpers\HaversineTrait;
use Illuminate\Support\Collection;




class FuelOrderService
{
    public function __construct(
        protected FuelOrderRepositoryInterface $repository,
        protected NotificationService $notifications,
        protected RadiusDispatchService $radiusDispatch
    ) {}

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

        $assignedProviderId = $order->fuel_provider_id;
        $providerUser = $order->fuelProvider?->user;

        $cancelled = $this->repository->cancel($order, $reason);

        if ($cancelled && $assignedProviderId && $providerUser && $providerUser->id !== $user->id) {
            $this->notifications->notifyUser(
                $providerUser,
                'fuel_order_cancelled_by_customer',
                'تم إلغاء طلب الوقود',
                'قام العميل بإلغاء طلب الوقود',
                [
                    'entity_type' => 'fuel_order',
                    'entity_id' => $order->id,
                    'action' => 'open_details',
                    'status' => 'cancelled',
                    'reason' => $reason,
                    'fuel_provider_id' => $assignedProviderId,
                ]
            );
        }

        return $cancelled;
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

        $this->radiusDispatch->advance(
            $order,
            'fuel',
            'fuel_provider',
            RadiusDispatchService::INITIAL_RADIUS_KM,
            fn (int $radius) => $this->getNearbyFuelProviders($order->delivery_latitude, $order->delivery_longitude, $radius),
            fn (Collection $new) => $this->notifyProviderBatch($order, $new)
        );

        return $order->load(['vehicle']);
    }

    public function expandDispatchRadius(int $orderId, int $expectedRadiusKm): void
    {
        DB::transaction(function () use ($orderId, $expectedRadiusKm) {
            $order = FuelOrder::whereKey($orderId)->lockForUpdate()->first();
            if (!$order) {
                return;
            }
            if ($order->status !== 'pending') {
                return;
            }
            if ((int) $order->current_radius_km !== $expectedRadiusKm) {
                return;
            }
            if (!$order->delivery_latitude || !$order->delivery_longitude) {
                return;
            }

            $this->radiusDispatch->advance(
                $order,
                'fuel',
                'fuel_provider',
                $expectedRadiusKm + RadiusDispatchService::RADIUS_STEP_KM,
                fn (int $radius) => $this->getNearbyFuelProviders($order->delivery_latitude, $order->delivery_longitude, $radius),
                fn (Collection $new) => $this->notifyProviderBatch($order, $new)
            );
        });
    }

    public function recheckMaxRadius(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = FuelOrder::whereKey($orderId)->lockForUpdate()->first();
            if (!$order) {
                return;
            }
            if ($order->status !== 'pending') {
                return;
            }
            $max = $this->radiusDispatch->maxRadiusKm();
            if ((int) $order->current_radius_km !== $max) {
                return;
            }
            if (!$order->delivery_latitude || !$order->delivery_longitude) {
                return;
            }

            $this->radiusDispatch->advance(
                $order,
                'fuel',
                'fuel_provider',
                $max,
                fn (int $radius) => $this->getNearbyFuelProviders($order->delivery_latitude, $order->delivery_longitude, $radius),
                fn (Collection $new) => $this->notifyProviderBatch($order, $new)
            );
        });
    }

    public function reevaluateDispatch(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = FuelOrder::whereKey($orderId)->lockForUpdate()->first();
            if (!$order) {
                return;
            }
            if ($order->status !== 'pending') {
                return;
            }
            if (!$order->delivery_latitude || !$order->delivery_longitude) {
                return;
            }

            $start = $order->current_radius_km ?? RadiusDispatchService::INITIAL_RADIUS_KM;

            $this->radiusDispatch->advance(
                $order,
                'fuel',
                'fuel_provider',
                $start,
                fn (int $radius) => $this->getNearbyFuelProviders($order->delivery_latitude, $order->delivery_longitude, $radius),
                fn (Collection $new) => $this->notifyProviderBatch($order, $new)
            );
        });
    }

    public function reannounceOrder(FuelOrder $order, ?int $excludeProviderId = null): void
    {
        if ($excludeProviderId !== null) {
            DispatchNotificationRecipient::insertOrIgnore([[
                'service_type' => 'fuel',
                'request_id' => $order->id,
                'recipient_type' => 'fuel_provider',
                'recipient_id' => $excludeProviderId,
                'notified_at' => now(),
            ]]);
        }

        $this->reevaluateDispatch($order->id);
    }

    private function notifyProviderBatch(FuelOrder $order, Collection $providers): void
    {
        foreach ($providers as $provider) {
            try {
                broadcast(new NewEmergencyFuelOrder($order, $provider, $provider->distance));
                $this->notifyEmergencyFuelRecipient($provider->user_id, $order);
                DispatchNotificationRecipient::insertOrIgnore([[
                    'service_type' => 'fuel',
                    'request_id' => $order->id,
                    'recipient_type' => 'fuel_provider',
                    'recipient_id' => $provider->id,
                    'notified_at' => now(),
                ]]);
            } catch (\Throwable $e) {
                Log::warning('fuel.dispatch.notify_recipient_failed', [
                    'order_id' => $order->id,
                    'provider_id' => $provider->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function notifyEmergencyFuelRecipient(int $userId, FuelOrder $order): void
    {
        $providerUser = User::find($userId);

        if (!$providerUser) {
            return;
        }

        $this->notifications->notifyUser(
            $providerUser,
            'new_emergency_fuel_order',
            'طلب وقود طارئ جديد',
            'يوجد طلب وقود طارئ بالقرب منك',
            [
                'entity_type' => 'emergency_fuel_order',
                'entity_id' => $order->id,
                'status' => 'pending',
            ]
        );
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
