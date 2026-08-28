<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\DispatchNotificationRecipient;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\NewSosRequest;
use App\Events\SosCancelledByCustomer;
use App\Helpers\HaversineTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SosService
{
    use HaversineTrait;

    public function __construct(
        protected SosRepositoryInterface $repository,
        protected NotificationService $notifications,
        protected RadiusDispatchService $radiusDispatch
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

        $this->radiusDispatch->advance(
            $sosRequest,
            'sos',
            'technician',
            RadiusDispatchService::INITIAL_RADIUS_KM,
            fn (int $radius) => $this->getNearbyTechnicians($sosRequest->lat, $sosRequest->lng, $radius),
            fn (Collection $new) => $this->notifyTechnicianBatch($sosRequest, $new)
        );

        return $sosRequest->load(['vehicle']);
    }

    public function expandDispatchRadius(int $sosId, int $expectedRadiusKm): void
    {
        DB::transaction(function () use ($sosId, $expectedRadiusKm) {
            $sosRequest = SosRequest::whereKey($sosId)->lockForUpdate()->first();
            if (!$sosRequest) {
                return;
            }
            if ($sosRequest->status !== 'open') {
                return;
            }
            if ((int) $sosRequest->current_radius_km !== $expectedRadiusKm) {
                return;
            }

            $this->radiusDispatch->advance(
                $sosRequest,
                'sos',
                'technician',
                $expectedRadiusKm + RadiusDispatchService::RADIUS_STEP_KM,
                fn (int $radius) => $this->getNearbyTechnicians($sosRequest->lat, $sosRequest->lng, $radius),
                fn (Collection $new) => $this->notifyTechnicianBatch($sosRequest, $new)
            );
        });
    }

    public function recheckMaxRadius(int $sosId): void
    {
        DB::transaction(function () use ($sosId) {
            $sosRequest = SosRequest::whereKey($sosId)->lockForUpdate()->first();
            if (!$sosRequest) {
                return;
            }
            if ($sosRequest->status !== 'open') {
                return;
            }
            $max = $this->radiusDispatch->maxRadiusKm();
            if ((int) $sosRequest->current_radius_km !== $max) {
                return;
            }

            $this->radiusDispatch->advance(
                $sosRequest,
                'sos',
                'technician',
                $max,
                fn (int $radius) => $this->getNearbyTechnicians($sosRequest->lat, $sosRequest->lng, $radius),
                fn (Collection $new) => $this->notifyTechnicianBatch($sosRequest, $new)
            );
        });
    }

    public function reevaluateDispatch(int $sosId): void
    {
        DB::transaction(function () use ($sosId) {
            $sosRequest = SosRequest::whereKey($sosId)->lockForUpdate()->first();
            if (!$sosRequest) {
                return;
            }
            if ($sosRequest->status !== 'open') {
                return;
            }

            $start = $sosRequest->current_radius_km ?? RadiusDispatchService::INITIAL_RADIUS_KM;

            $this->radiusDispatch->advance(
                $sosRequest,
                'sos',
                'technician',
                $start,
                fn (int $radius) => $this->getNearbyTechnicians($sosRequest->lat, $sosRequest->lng, $radius),
                fn (Collection $new) => $this->notifyTechnicianBatch($sosRequest, $new)
            );
        });
    }

    private function notifyTechnicianBatch(SosRequest $sosRequest, Collection $technicians): void
    {
        foreach ($technicians as $technician) {
            try {
                broadcast(new NewSosRequest($sosRequest, $technician, $technician->distance));
                $this->notifySosRecipient($technician->user_id, $sosRequest);
                DispatchNotificationRecipient::insertOrIgnore([[
                    'service_type' => 'sos',
                    'request_id' => $sosRequest->id,
                    'recipient_type' => 'technician',
                    'recipient_id' => $technician->id,
                    'notified_at' => now(),
                ]]);
            } catch (\Throwable $e) {
                Log::warning('sos.dispatch.notify_recipient_failed', [
                    'sos_id' => $sosRequest->id,
                    'technician_id' => $technician->id,
                    'error' => $e->getMessage(),
                ]);
            }
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
