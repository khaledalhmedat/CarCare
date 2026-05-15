<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\SosRequestAccepted;
use App\Events\SosRequestStatusUpdated;
use App\Events\SosRequestCancelled;
use Illuminate\Support\Facades\DB;

class TechnicianSosService
{
    public function __construct(protected SosRepositoryInterface $repository) {}


    public function getAvailableRequests(?string $city = null)
    {
        $query = SosRequest::where('status', 'open')
            ->with(['user', 'vehicle'])
            ->latest();

        if ($city) {
            $query->whereHas('user', function ($q) use ($city) {
                $q->where('city', $city);
            });
        }

        return $query->paginate(15);
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
        $sosRequest = $this->repository->find($requestId);

        if (!$sosRequest) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($sosRequest->status !== 'open') {
            throw new \Exception('هذا الطلب غير متاح للقبول');
        }

        try {
            DB::beginTransaction();

            $this->repository->assignTechnician($sosRequest, $technician->id);

            broadcast(new SosRequestAccepted($sosRequest, $technician));

            DB::commit();

            return $sosRequest->fresh(['user', 'vehicle']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
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

        if (!in_array($status, ['in_progress', 'completed'])) {
            throw new \Exception('الحالة غير صحيحة');
        }

        try {
            DB::beginTransaction();

            $data = ['status' => $status];

            if ($status === 'completed') {
                $data['completed_at'] = now();
            }

            $this->repository->update($sosRequest, $data);

            broadcast(new SosRequestStatusUpdated($sosRequest));

            DB::commit();

            return $sosRequest->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
            'cancelled_requests' => SosRequest::where('technician_id', $technician->id)
                ->where('status', 'cancelled')->count(),
        ];
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

        try {
            DB::beginTransaction();

            $sosRequest->update([
                'status' => 'open',
                'technician_id' => null,
                'accepted_at' => null,
                'cancellation_reason' => $reason,
            ]);

            broadcast(new SosRequestCancelled($sosRequest, $technician, $reason));


            DB::commit();

            return $sosRequest->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
