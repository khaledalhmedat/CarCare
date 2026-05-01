<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\NewSosRequest;
use App\Helpers\HaversineTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SosService
{

    use HaversineTrait; 

    public function __construct(protected SosRepositoryInterface $repository) {}

    
    public function createRequest(User $user, array $data): SosRequest
    {
        try {
            DB::beginTransaction();

            $vehicle = $user->vehicles()->find($data['vehicle_id']);
            if (!$vehicle) {
                throw new \Exception('المركبة غير موجودة');
            }

            $city = $data['city'] ?? $this->getCityFromCoordinates($data['lat'], $data['lng']);

            $sosRequest = $this->repository->createForUser($user, [
                'vehicle_id' => $data['vehicle_id'],
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'city' => $city,
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'priority' => 'emergency',
            ]);

            $nearbyTechnicians = $this->getNearbyTechnicians($data['lat'], $data['lng'], 30);
            
            foreach ($nearbyTechnicians as $technician) {
                broadcast(new NewSosRequest($sosRequest, $technician, $technician->distance));
            }

            DB::commit();
            return $sosRequest->load(['vehicle']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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

    public function getUserRequests(User $user, ?string $status = null)
    {
        return $this->repository->getUserRequests($user, $status);
    }

    public function getRequest(int $id, User $user): SosRequest
    {
        $request = $this->repository->find($id);
        if (!$request || $request->user_id !== $user->id) {
            throw new \Exception('الطلب غير موجود');
        }
        return $request;
    }


    public function cancelRequest(int $id, User $user, string $reason): bool
    {
        $request = $this->getRequest($id, $user);
        if (!in_array($request->status, ['open', 'accepted'])) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }
        return $this->repository->cancel($request, $reason);
    }
}