<?php

namespace App\Repositories\Contracts;

use App\Models\Vehicle;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface VehicleRepositoryInterface
{
    public function find(int $id): ?Vehicle;
    public function findByPlate(string $plateNumber): ?Vehicle;
    public function getUserVehicles(User $user): Collection;
    public function getUserVehiclesPaginated(User $user, int $perPage = 15): LengthAwarePaginator;
    public function createForUser(User $user, array $data): Vehicle;
    public function update(Vehicle $vehicle, array $data): bool;
    public function delete(Vehicle $vehicle): bool;
    public function getVehiclesWithMaintenanceHistory(int $userId): Collection;
}