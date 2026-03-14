<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use App\Repositories\Contracts\VehicleRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;



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



    private function uploadImage($image, ?Vehicle $vehicle = null): ?string
    {
        if (!$image) {
            return null;
        }

        if ($vehicle && $vehicle->image) {
            Storage::disk('public')->delete($vehicle->image);
        }

        $path = $image->store('vehicles', 'public');
        return $path;
    }


    public function createVehicle(User $user, array $data): Vehicle
    {
        try {
            DB::beginTransaction();

            if (isset($data['image'])) {
                $data['image'] = $this->uploadImage($data['image']);
            }

            $vehicle = $this->vehicleRepository->createForUser($user, $data);

            DB::commit();
            return $vehicle;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function updateVehicle(int $id, User $user, array $data): Vehicle
    {
        $vehicle = $this->getVehicle($id, $user);

        try {
            DB::beginTransaction();

            if (isset($data['image'])) {
                $data['image'] = $this->uploadImage($data['image'], $vehicle);
            }

            $this->vehicleRepository->update($vehicle, $data);

            DB::commit();
            return $vehicle->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function deleteVehicle(int $id, User $user): bool
    {
        $vehicle = $this->getVehicle($id, $user);

        try {
            DB::beginTransaction();

            if ($vehicle->image) {
                Storage::disk('public')->delete($vehicle->image);
            }

            $this->vehicleRepository->delete($vehicle);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
