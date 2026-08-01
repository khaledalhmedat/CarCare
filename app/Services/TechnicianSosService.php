<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\SosRequestAccepted;
use App\Events\SosRequestStatusUpdated;
use App\Events\SosRequestCancelled;
use App\Events\NewSosRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TechnicianSosService
{
    public function __construct(protected SosRepositoryInterface $repository) {}


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
            foreach ($allRequests as $request) {
                $distance = $this->calculateDistance(
                    $technicianLat,
                    $technicianLng,
                    $request->lat,
                    $request->lng
                );

                if ($distance <= 30) {
                    $request->distance = round($distance, 2);
                    $finalRequests->push($request);
                    $addedIds->push($request->id);
                }
            }

            Log::info(' Nearby requests (within 30km):', [
                'count' => $finalRequests->count(),
                'ids' => $finalRequests->pluck('id')->toArray()
            ]);
        }

        if ($technicianCity) {
            foreach ($allRequests as $request) {
                if ($request->city === $technicianCity && !$addedIds->contains($request->id)) {
                    $request->distance = null;
                    $finalRequests->push($request);
                    $addedIds->push($request->id);
                }
            }

            Log::info(' Same city requests (all distances, no distance filter):', [
                'technician_city' => $technicianCity,
                'added_count' => $finalRequests->count() - ($finalRequests->count() - $addedIds->count()),
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

        return $sosRequest->fresh(['user', 'vehicle']);
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
