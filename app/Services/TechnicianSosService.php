<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\SosRequestAccepted;
use App\Events\SosRequestStatusUpdated;
use App\Events\SosRequestCancelled;
use App\Events\NewSosRequest;
use App\Exceptions\ServiceAcceptanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TechnicianSosService
{
    // Kept in sync with the 30km radius used by getAvailableRequests() below.
    private const MAX_SERVICE_DISTANCE_KM = 30;

    public function __construct(
        protected SosRepositoryInterface $repository,
        protected NotificationService $notifications
    ) {}


    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        if (!$lat1 || !$lon1 || !$lat2 || !$lon2) {
            return PHP_INT_MAX;
        }

        $theta = $lon1 - $lon2;
        $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        $km = $miles * 1.609344;

        return $km;
    }


    public function getAvailableRequests(?float $technicianLat = null, ?float $technicianLng = null, ?string $technicianCity = null)
    {
        $allRequests = SosRequest::where('status', 'open')
            ->with(['user', 'vehicle'])
            ->latest()
            ->get();

        $finalRequests = collect();
        $addedIds = collect();

        if ($technicianLat && $technicianLng) {
            // Technician location is known: distance is the only filter. City is
            // intentionally NOT used as an alternate match here anymore, since a
            // same-city match can still be well beyond MAX_SERVICE_DISTANCE_KM.
            foreach ($allRequests as $request) {
                $distance = $this->calculateDistance(
                    $technicianLat,
                    $technicianLng,
                    $request->lat,
                    $request->lng
                );

                if ($distance <= self::MAX_SERVICE_DISTANCE_KM) {
                    $request->distance = round($distance, 2);
                    $finalRequests->push($request);
                    $addedIds->push($request->id);
                }
            }

            Log::info(' Nearby requests (within ' . self::MAX_SERVICE_DISTANCE_KM . 'km):', [
                'count' => $finalRequests->count(),
                'ids' => $finalRequests->pluck('id')->toArray()
            ]);
        } elseif ($technicianCity) {
            // Fallback only: technician has no coordinates yet, so distance can't be
            // computed at all. City is the best available signal in that case.
            foreach ($allRequests as $request) {
                if ($request->city === $technicianCity && !$addedIds->contains($request->id)) {
                    $request->distance = null;
                    $finalRequests->push($request);
                    $addedIds->push($request->id);
                }
            }

            Log::info(' Same city requests (technician location unknown, no distance filter possible):', [
                'technician_city' => $technicianCity,
                'added_count' => $finalRequests->count(),
                'new_ids' => $finalRequests->pluck('id')->toArray()
            ]);
        }

        Log::info(' FINAL RESULTS:', [
            'total_final_requests' => $finalRequests->count(),
            'all_ids' => $finalRequests->pluck('id')->toArray(),
            'distances' => $finalRequests->map(function ($r) {
                return ['id' => $r->id, 'distance' => $r->distance ?? 'null (same city only)'];
            })->toArray()
        ]);

        $page = request()->get('page', 1);
        $perPage = 15;
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $finalRequests->forPage($page, $perPage),
            $finalRequests->count(),
            $perPage,
            $page,
            ['path' => request()->url()]
        );

        return $paginated;
    }
    public function getRequestDetails(int $id): SosRequest
    {
        $request = $this->repository->find($id);
        if (!$request) {
            throw new \Exception('الطلب غير موجود');
        }
        return $request;
    }

    public function acceptRequest(User $technician, int $requestId, array $data): SosRequest
    {
        $technicianProfile = $technician->technician;

        if (!$technicianProfile || $technicianProfile->status !== 'approved') {
            throw new \Exception('حسابك كتقني لم يتم اعتماده بعد من الإدارة');
        }

        // Pre-acceptance range check: read-only, done before the row lock below so
        // an out-of-range technician never mutates the request. Loaded outside the
        // transaction on purpose — this only reads coordinates that don't change.
        $requestForRangeCheck = SosRequest::find($requestId);
        if ($requestForRangeCheck) {
            $this->assertWithinServiceRange($technicianProfile, $requestForRangeCheck);
        }

        // Lock the row so two technicians cannot claim the same open request:
        // whoever acquires the lock first accepts; the other re-reads 'accepted' and is rejected.
        $sosRequest = DB::transaction(function () use ($technician, $requestId) {
            $request = SosRequest::whereKey($requestId)->lockForUpdate()->first();

            if (!$request) {
                throw new \Exception('الطلب غير موجود');
            }

            if ($request->status !== 'open') {
                throw new \Exception('هذا الطلب غير متاح للقبول');
            }

            $request->update([
                'technician_id' => $technician->id,
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            return $request;
        });

        // broadcast after commit — a broadcast failure must not undo the accept
        try {
            broadcast(new SosRequestAccepted($sosRequest, $technician));
        } catch (\Throwable $e) {
            Log::warning('sos.accept.broadcast_failed', ['sos_id' => $requestId, 'error' => $e->getMessage()]);
        }

        $customer = $sosRequest->user;
        if ($customer && $customer->id !== $technician->id) {
            $this->notifications->notifyUser(
                $customer,
                'sos_accepted',
                'تم قبول طلب الطوارئ',
                'تم قبول طلب الطوارئ الخاص بك، والفني في طريقه إليك',
                [
                    'entity_type' => 'sos_request',
                    'entity_id' => $sosRequest->id,
                    'action' => 'open_details',
                    'status' => 'accepted',
                    'technician_id' => $technician->id,
                ]
            );
        }

        return $sosRequest->fresh(['user', 'vehicle']);
    }

    /**
     * Mirrors the distance rule already enforced in getAvailableRequests() above,
     * but as a hard gate on acceptance instead of a listing filter.
     */
    private function assertWithinServiceRange(Technician $technicianProfile, SosRequest $sosRequest): void
    {
        if (!$technicianProfile->latitude || !$technicianProfile->longitude) {
            throw new ServiceAcceptanceException(
                'تعذر التحقق من نطاق الخدمة لأن موقع مقدم الخدمة غير محدد.',
                'PROVIDER_LOCATION_REQUIRED'
            );
        }

        // lat/lng are NOT NULL on sos_requests, but guard defensively anyway.
        if (!$sosRequest->lat || !$sosRequest->lng) {
            throw new ServiceAcceptanceException(
                'تعذر التحقق من نطاق الخدمة لأن موقع الطلب غير محدد.',
                'REQUEST_LOCATION_REQUIRED'
            );
        }

        $distance = $this->calculateDistance(
            $technicianProfile->latitude,
            $technicianProfile->longitude,
            $sosRequest->lat,
            $sosRequest->lng
        );

        if ($distance > self::MAX_SERVICE_DISTANCE_KM) {
            throw new ServiceAcceptanceException(
                'الطلب خارج نطاق التغطية. لا يمكن قبول الطلبات التي تبعد أكثر من 30 كم عن موقعك.',
                'OUT_OF_SERVICE_RANGE',
                [
                    'max_distance_km' => self::MAX_SERVICE_DISTANCE_KM,
                    'distance_km' => round($distance, 2),
                ]
            );
        }
    }

    public function updateStatus(User $technician, int $requestId, string $status): SosRequest
    {
        $sosRequest = $this->repository->find($requestId);

        if (!$sosRequest) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($sosRequest->technician_id !== $technician->id) {
            throw new \Exception('لا تملك صلاحية تحديث هذا الطلب');
        }

        // only allow forward transitions; blocks completing a cancelled/completed/open request
        $allowedFrom = ['in_progress' => ['accepted'], 'completed' => ['accepted', 'in_progress']];
        if (!isset($allowedFrom[$status]) || !in_array($sosRequest->status, $allowedFrom[$status], true)) {
            throw new \Exception('لا يمكن تحديث حالة الطلب في وضعه الحالي');
        }

        $data = ['status' => $status];

        if ($status === 'in_progress') {
            $data['started_at'] = now();
        }

        if ($status === 'completed') {
            $data['completed_at'] = now();
        }

        $sosRequest->update($data);

        try {
            broadcast(new SosRequestStatusUpdated($sosRequest));
        } catch (\Throwable $e) {
            Log::warning('sos.status.broadcast_failed', ['sos_id' => $requestId, 'error' => $e->getMessage()]);
        }

        $customer = $sosRequest->user;
        if ($customer && $customer->id !== $technician->id) {
            if ($status === 'in_progress') {
                $this->notifications->notifyUser(
                    $customer,
                    'sos_in_progress',
                    'بدأ تنفيذ طلب الطوارئ',
                    'بدأ الفني تنفيذ خدمة الطوارئ الخاصة بك',
                    [
                        'entity_type' => 'sos_request',
                        'entity_id' => $sosRequest->id,
                        'action' => 'open_details',
                        'status' => 'in_progress',
                        'technician_id' => $technician->id,
                    ]
                );
            } elseif ($status === 'completed') {
                $this->notifications->notifyUser(
                    $customer,
                    'sos_completed',
                    'تم إنجاز طلب الطوارئ',
                    'تم إنجاز خدمة الطوارئ الخاصة بمركبتك',
                    [
                        'entity_type' => 'sos_request',
                        'entity_id' => $sosRequest->id,
                        'action' => 'open_details',
                        'status' => 'completed',
                        'technician_id' => $technician->id,
                    ]
                );
            }
        }

        return $sosRequest->fresh();
    }

    public function cancelRequest(User $technician, int $requestId, string $reason): SosRequest
    {
        $sosRequest = $this->repository->find($requestId);

        if (!$sosRequest) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($sosRequest->technician_id !== $technician->id) {
            throw new \Exception('لا تملك صلاحية إلغاء هذا الطلب');
        }

        if (!in_array($sosRequest->status, ['accepted', 'in_progress'])) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }

        $sosRequest->update([
            'status' => 'open',
            'technician_id' => null,
            'accepted_at' => null,
            'cancellation_reason' => $reason,
        ]);

        try {
            broadcast(new SosRequestCancelled($sosRequest, $technician, $reason));
            broadcast(new NewSosRequest($sosRequest, null, null));
        } catch (\Throwable $e) {
            Log::warning('technician_sos.cancel.broadcast_failed', [
                'sos_id' => $sosRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        $customer = $sosRequest->user;
        if ($customer && $customer->id !== $technician->id) {
            $this->notifications->notifyUser(
                $customer,
                'sos_reopened_after_technician_cancel',
                'تمت إعادة طلب الطوارئ',
                'ألغى الفني استلام طلب الطوارئ، وتمت إعادة الطلب للبحث عن فني آخر',
                [
                    'entity_type' => 'sos_request',
                    'entity_id' => $sosRequest->id,
                    'action' => 'open_details',
                    'status' => 'open',
                    'technician_id' => $technician->id,
                    'reason' => $reason,
                ]
            );
        }

        return $sosRequest->fresh();
    }

    public function getMyRequests(User $technician, ?string $status = null)
    {
        $query = SosRequest::where('technician_id', $technician->id)
            ->with(['user', 'vehicle']);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->latest()->paginate(15);
    }

    public function getStatistics(User $technician): array
    {
        return [
            'accepted_requests' => SosRequest::where('technician_id', $technician->id)->count(),
            'completed_requests' => SosRequest::where('technician_id', $technician->id)
                ->where('status', 'completed')->count(),
            'in_progress_requests' => SosRequest::where('technician_id', $technician->id)
                ->where('status', 'in_progress')->count(),
            'cancelled_requests' => SosRequest::where('technician_id', $technician->id)
                ->where('status', 'cancelled')->count(),
        ];
    }
}
