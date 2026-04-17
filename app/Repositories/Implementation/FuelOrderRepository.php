<?php

namespace App\Repositories\Implementation;

use App\Models\FuelOrder;
use App\Models\User;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class FuelOrderRepository implements FuelOrderRepositoryInterface
{
    public function __construct(protected FuelOrder $model) {}

    public function find(int $id): ?FuelOrder
    {
        return $this->model->with(['vehicle', 'fuelProvider.user'])->find($id);
    }

    public function getUserOrders(User $user, ?string $status = null): LengthAwarePaginator
    {
        $query = $user->fuelOrders()->with(['vehicle', 'fuelProvider'])->latest();
        if ($status) {
            $query->where('status', $status);
        }
        return $query->paginate(15);
    }

    public function createForUser(User $user, array $data): FuelOrder
    {
        return $user->fuelOrders()->create($data);
    }

    public function update(FuelOrder $order, array $data): bool
    {
        return $order->update($data);
    }

    public function cancel(FuelOrder $order, string $reason): bool
    {
        return $order->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }

    public function assignProvider(FuelOrder $order, int $providerId): bool
    {
        return $order->update([
            'fuel_provider_id' => $providerId,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function updateStatus(FuelOrder $order, string $status): bool
    {
        $data = ['status' => $status];
        if ($status === 'in_progress') $data['started_at'] = now();
        if ($status === 'completed') $data['completed_at'] = now();
        return $order->update($data);
    }
}
