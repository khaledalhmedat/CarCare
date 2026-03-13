<?php

namespace App\Services;

use App\Models\User;
use App\Models\MaintenanceRequest;
use App\Models\Quotation;
use App\Models\ServiceJob;
use App\Models\MaintenanceRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TechnicianMaintenanceService
{

    public function getAvailableRequests(User $user, array $filters = [])
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $query = MaintenanceRequest::where('status', 'pending')
            ->with(['user', 'vehicle', 'photos'])
            ->latest();
        if (isset($filters['city']) && $filters['city']) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('city', $filters['city']);
            });
        }

        if (isset($filters['priority']) && $filters['priority']) {
            $query->where('priority', $filters['priority']);
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }


    public function getRequestDetails(User $user, int $requestId): MaintenanceRequest
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $request = MaintenanceRequest::with(['user', 'vehicle', 'photos', 'quotations' => function ($q) use ($technician) {
            $q->where('technician_id', $technician->id);
        }])->find($requestId);

        if (!$request) {
            throw new \Exception('الطلب غير موجود');
        }

        return $request;
    }




    public function submitQuotation(User $user, int $requestId, array $data): Quotation
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $maintenanceRequest = MaintenanceRequest::find($requestId);

        if (!$maintenanceRequest) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($maintenanceRequest->status !== 'pending') {
            throw new \Exception('لا يمكن تقديم عرض على هذا الطلب حالياً');
        }

        $existingQuotation = Quotation::where('maintenance_request_id', $requestId)
            ->where('technician_id', $technician->user_id)
            ->first();

        if ($existingQuotation) {
            throw new \Exception('لقد قدمت عرضاً مسبقاً على هذا الطلب');
        }

        try {
            DB::beginTransaction();

            $quotation = Quotation::create([
                'maintenance_request_id' => $requestId,
                'technician_id' => $technician->user_id,
                'price' => $data['price'],
                'estimated_days' => $data['estimated_days'],
                'notes' => $data['notes'] ?? null,
                'parts_included' => $data['parts_included'] ?? false,
                'status' => 'pending',
            ]);

            if ($maintenanceRequest->quotations()->count() === 0) {
                $maintenanceRequest->update(['status' => 'quoted']);
            }

            DB::commit();

            return $quotation->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getMyJobs(User $user, string $status = null)
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $query = ServiceJob::where('technician_id', $technician->user_id)
            ->with(['maintenanceRequest.user', 'maintenanceRequest.vehicle']);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->latest()->paginate(15);
    }


    public function getMyAcceptedJobs(User $user)
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $query = ServiceJob::where('technician_id', $technician->user_id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with(['maintenanceRequest.user', 'maintenanceRequest.vehicle'])
            ->latest()
            ->get();
    }


    public function updateJobStatus(User $user, int $jobId, array $data): ServiceJob
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $job = ServiceJob::where('id', $jobId)
            ->where('technician_id', $technician->id)
            ->first();

        if (!$job) {
            throw new \Exception('الطلب غير موجود أو لا يخصك');
        }

        try {
            DB::beginTransaction();

            $job->update([
                'status' => $data['status'],
            ]);

            $maintenanceRequest = $job->maintenanceRequest;
            $maintenanceRequest->update([
                'status' => $data['status'] === 'in_progress' ? 'in_progress' : 'completed'
            ]);

            if ($data['status'] === 'completed') {
                MaintenanceRecord::create([
                    'service_job_id' => $job->id,
                    'vehicle_id' => $maintenanceRequest->vehicle_id,
                    'details' => $data['completion_notes'] ?? 'تم إنجاز الصيانة',
                    'parts_used' => isset($data['parts_used']) ? json_encode($data['parts_used']) : null,
                    'completed_at' => now(),
                ]);
            }

            DB::commit();

            return $job->fresh(['maintenanceRequest', 'maintenanceRecord']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function addMaintenanceRecord(User $user, int $jobId, array $data): MaintenanceRecord
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $job = ServiceJob::where('id', $jobId)
            ->where('technician_id', $technician->id)
            ->with('maintenanceRequest')
            ->first();

        if (!$job) {
            throw new \Exception('الطلب غير موجود أو لا يخصك');
        }

        if ($job->status !== 'completed') {
            throw new \Exception('لا يمكن إضافة تقرير إلا للمهام المكتملة');
        }

        if ($job->maintenanceRecord) {
            throw new \Exception('يوجد تقرير مسبق لهذه المهمة');
        }

        $record = MaintenanceRecord::create([
            'service_job_id' => $job->id,
            'vehicle_id' => $job->maintenanceRequest->vehicle_id,
            'details' => $data['details'],
            'parts_used' => isset($data['parts_used']) ? json_encode($data['parts_used']) : null,
            'recommendations' => $data['recommendations'] ?? null,
        ]);

        return $record->fresh();
    }
}
