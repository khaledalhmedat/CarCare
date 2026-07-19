<?php

namespace App\Services;

use App\Models\User;
use App\Models\FuelProvider;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class FuelProviderService
{
    public function getProfile(User $user): ?FuelProvider
    {
        return $user->fuelProvider;
    }

    public function createOrUpdateProfile(User $user, array $data): FuelProvider
    {
        try {
            DB::beginTransaction();

            $provider = $user->fuelProvider;

            if ($provider) {
                $provider->update($data);
            } else {
                $data['user_id'] = $user->id;
                $data['status'] = 'pending';
                $provider = FuelProvider::create($data);

                $role = Role::where('slug', 'fuel-provider')->first();
                if ($role && !$user->hasRole('fuel-provider')) {
                    $user->roles()->attach($role->id);
                }
            }

            DB::commit();
            return $provider->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateAvailability(User $user, bool $isAvailable): bool
    {
        $provider = $user->fuelProvider;
        if (!$provider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }
        return $provider->update(['is_available' => $isAvailable]);
    }

    public function updatePrices(User $user, array $prices): bool
    {
        $provider = $user->fuelProvider;
        if (!$provider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }
        return $provider->update(['prices' => $prices]);
    }

    public function getStatistics(User $user): array
    {
        $provider = $user->fuelProvider;
        if (!$provider) {
            throw new \Exception('لم تقم بإدخال معلومات مزود الوقود بعد');
        }

        $orders = $provider->fuelOrders();

        return [
            'total_orders' => $orders->count(),
            'pending_orders' => (clone $orders)->where('status', 'pending')->count(),
            'accepted_orders' => (clone $orders)->where('status', 'accepted')->count(),
            'in_progress_orders' => (clone $orders)->where('status', 'in_progress')->count(),
            'completed_orders' => (clone $orders)->where('status', 'completed')->count(),
            'cancelled_orders' => (clone $orders)->where('status', 'cancelled')->count(),
            'total_revenue' => (clone $orders)->where('status', 'completed')->sum('total_price'),
        ];
    }
}
