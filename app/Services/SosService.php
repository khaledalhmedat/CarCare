<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\NewSosRequest;
use App\Events\SosCancelledByCustomer;
use App\Helpers\HaversineTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SosService
{
    use HaversineTrait;

    public function __construct(
        protected SosRepositoryInterface $repository,
        protected NotificationService $notifications
    ) {}


    public function createRequest(User $user, array $data): SosRequest
    {
        $vehicle = $user->vehicles()->find($data['vehicle_id']);
        if (!$vehicle) {
            throw new \Exception('المركبة غير موجودة');
        }

        $sosRequest = DB::transaction(function () use ($user, $data) {
            return $this->repository->createForUser($user, [
                'vehicle_id' => $data['vehicle_id'],
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'city' => $data['city'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'priority' => 'emergency',
            ]);
        });

        $this->notifyTechnicians($sosRequest, $data);

        return $sosRequest->load(['vehicle']);
    }

    protected function notifyTechnicians(SosRequest $sosRequest, array $data): void
    {
        try {
            $nearbyTechnicians = $this->getNearbyTechnicians($data['lat'], $data['lng'], 30);

            if ($nearbyTechnicians->isNotEmpty()) {
                foreach ($nearbyTechnicians as $technician) {
                    broadcast(new NewSosRequest($sosRequest, $technician, $technician->distance));
                    $this->notifySosRecipient($technician->user_id, $sosRequest);
                }

                Log::info(' SOS: Notified ' . $nearbyTechnicians->count() . ' technicians by coordinates', [
                    'sos_id' => $sosRequest->id,
                    'technicians' => $nearbyTechnicians->pluck('id')->toArray()
                ]);

                return;
            }

            $city = $data['city'] ?? null;

            if (!$city) {
                Log::warning('❌ SOS: No city provided and no nearby technicians', [
                    'sos_id' => $sosRequest->id
                ]);

                return;
            }

            $cityTechnicians = Technician::where('is_available', true)
                ->where('status', 'approved')
                ->where('city', $city)
                ->get();

            if ($cityTechnicians->isNotEmpty()) {
                foreach ($cityTechnicians as $technician) {
                    broadcast(new NewSosRequest($sosRequest, $technician, null));
                    $this->notifySosRecipient($technician->user_id, $sosRequest);
                }

                Log::info(' SOS: Notified ' . $cityTechnicians->count() . ' technicians in city: ' . $city, [
                    'sos_id' => $sosRequest->id,
                    'technicians' => $cityTechnicians->pluck('id')->toArray()
                ]);
            } else {
                Log::warning('❌ SOS: No technicians found in city: ' . $city, [
                    'sos_id' => $sosRequest->id
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('sos.notify_failed', [
                'sos_id' => $sosRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }


    protected function notifySosRecipient(int $userId, SosRequest $sosRequest): void
    {
        $technicianUser = User::find($userId);

        if (!$technicianUser) {
            return;
        }

        $this->notifications->notifyUser(
            $technicianUser,
            'new_sos_request',
            'طلب طوارئ جديد',
            'يوجد طلب مساعدة طارئ بالقرب منك',
            [
                'entity_type' => 'sos_request',
                'entity_id' => $sosRequest->id,
                'status' => 'open',
            ]
        );
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

        $assignedTechnicianId = $request->technician_id;

        $cancelled = $this->repository->cancel($request, $reason);

        if ($cancelled && $assignedTechnicianId) {
            $technician = User::find($assignedTechnicianId);
            if ($technician && $technician->id !== $user->id) {
                try {
                    broadcast(new SosCancelledByCustomer($request, $technician, $reason));
                } catch (\Throwable $e) {
                    Log::warning('sos.customer_cancel.broadcast_failed', [
                        'sos_id' => $request->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->notifications->notifyUser(
                    $technician,
                    'sos_cancelled_by_customer',
                    'تم إلغاء طلب الطوارئ',
                    'قام العميل بإلغاء طلب الطوارئ',
                    [
                        'entity_type' => 'sos_request',
                        'entity_id' => $request->id,
                        'action' => 'open_details',
                        'status' => 'cancelled',
                        'reason' => $reason,
                    ]
                );
            }
        }

        return $cancelled;
    }
}
