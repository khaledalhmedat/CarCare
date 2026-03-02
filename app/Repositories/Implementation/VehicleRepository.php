<?php

namespace App\Repositories\Implementation;

use App\Models\Vehicle;
use App\Models\User;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class VehicleRepository implements VehicleRepositoryInterface
{
    public function __construct(
        protected Vehicle $model
    ) {}

    public function find(int $id): ?Vehicle
    {
        return $this->model->find($id);
    }

    public function findByPlate(string $plateNumber): ?Vehicle
    {
        return $this->model->where('plate_number', $plateNumber)->first();
    }

    public function getUserVehicles(User $user): Collection
    {
        return $user->vehicles()->latest()->get();
    }

    public function getUserVehiclesPaginated(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->vehicles()->with(['maintenanceRecords' => function($q) {
            $q->latest()->limit(5);
        }])->latest()->paginate($perPage);
    }

    public function createForUser(User $user, array $data): Vehicle
    {
        return $user->vehicles()->create($data);
    }

    public function update(Vehicle $vehicle, array $data): bool
    {
        return $vehicle->update($data);
    }

    public function delete(Vehicle $vehicle): bool
    {
        return $vehicle->delete();
    }

    public function getVehiclesWithMaintenanceHistory(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->with(['maintenanceRecords' => function($q) {
                $q->latest()->limit(10);
            }])
            ->get();
    }
}