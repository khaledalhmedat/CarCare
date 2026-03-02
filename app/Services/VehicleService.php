<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class VehicleService
{
    public function __construct(
        protected VehicleRepositoryInterface $vehicleRepository
    ) {}

    
    public function getUserVehicles(User $user, bool $paginate = true, int $perPage = 15)
    {
        if ($paginate) {
            return $this->vehicleRepository->getUserVehiclesPaginated($user, $perPage);
        }
        
        return $this->vehicleRepository->getUserVehicles($user);
    }

    
    public function getVehicle(int $id, User $user): ?Vehicle
    {
        $vehicle = $this->vehicleRepository->find($id);
        
        if (!$vehicle || $vehicle->user_id !== $user->id) {
            throw new \Exception('المركبة غير موجودة أو لا تملك صلاحية الوصول إليها');
        }
        
        return $vehicle->load(['maintenanceRecords', 'fuelLogs', 'maintenanceAlerts']);
    }

    
    public function createVehicle(User $user, array $data): Vehicle
    {
        return $this->vehicleRepository->createForUser($user, $data);
    }

    
    public function updateVehicle(int $id, User $user, array $data): Vehicle
    {
        $vehicle = $this->vehicleRepository->find($id);
        
        if (!$vehicle || $vehicle->user_id !== $user->id) {
            throw new \Exception('المركبة غير موجودة أو لا تملك صلاحية تعديلها');
        }
        
        $this->vehicleRepository->update($vehicle, $data);
        
        return $vehicle->fresh();
    }

    
    public function deleteVehicle(int $id, User $user): bool
    {
        $vehicle = $this->vehicleRepository->find($id);
        
        if (!$vehicle || $vehicle->user_id !== $user->id) {
            throw new \Exception('المركبة غير موجودة أو لا تملك صلاحية حذفها');
        }
        
        return $this->vehicleRepository->delete($vehicle);
    }

    
    public function getMaintenanceHistory(int $vehicleId, User $user)
    {
        $vehicle = $this->vehicleRepository->find($vehicleId);
        
        if (!$vehicle || $vehicle->user_id !== $user->id) {
            throw new \Exception('المركبة غير موجودة');
        }
        
        return $vehicle->maintenanceRecords()
            ->with(['serviceJob.technician'])
            ->latest()
            ->paginate(15);
    }
}