<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\NewSosRequest;
use App\Helpers\HaversineTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

            $sosRequest = $this->repository->createForUser($user, [
                'vehicle_id' => $data['vehicle_id'],
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'city' => $data['city'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'priority' => 'emergency',
            ]);

            $nearbyTechnicians = $this->getNearbyTechnicians($data['lat'], $data['lng'], 30);

            if ($nearbyTechnicians->isNotEmpty()) {
                foreach ($nearbyTechnicians as $technician) {
                    broadcast(new NewSosRequest($sosRequest, $technician, $technician->distance));
                }

                Log::info('✅ SOS: Notified ' . $nearbyTechnicians->count() . ' technicians by coordinates', [
                    'sos_id' => $sosRequest->id,
                    'technicians' => $nearbyTechnicians->pluck('id')->toArray()
                ]);
            } else {
                $city = $data['city'] ?? null;

                if ($city) {
                    $cityTechnicians = Technician::where('is_available', true)
                        ->where('status', 'approved')
                        ->where('city', $city)
                        ->get();

                    if ($cityTechnicians->isNotEmpty()) {
                        foreach ($cityTechnicians as $technician) {
                            broadcast(new NewSosRequest($sosRequest, $technician, null));
                        }

                        Log::info('✅ SOS: Notified ' . $cityTechnicians->count() . ' technicians in city: ' . $city, [
                            'sos_id' => $sosRequest->id,
                            'technicians' => $cityTechnicians->pluck('id')->toArray()
                        ]);
                    } else {
                        Log::warning('❌ SOS: No technicians found in city: ' . $city, [
                            'sos_id' => $sosRequest->id
                        ]);
                    }
                } else {
                    Log::warning('❌ SOS: No city provided and no nearby technicians', [
                        'sos_id' => $sosRequest->id
                    ]);
                }
            }

            DB::commit();
            return $sosRequest->load(['vehicle']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    protected function getNearbyTechnicians(float $lat, float $lng, int $radiusInKm = 30)
    {
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        return Technician::where('is_available', true)
            ->where('status', 'approved')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
            ->having('distance', '<=', $radiusInKm)
            ->orderBy('distance')
            ->get()
            ->map(function ($technician) {
                $technician->distance = round($technician->distance, 2);
                return $technician;
            });
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
