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
    public function __construct(
        protected NotificationService $notifications
    ) {}

    public function getAvailableRequests(User $user, array $filters = [])
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        if ($technician->status !== 'approved') {
            throw new \Exception('حسابك كتقني لم يتم اعتماده بعد من الإدارة');
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

        if ($technician->status !== 'approved') {
            throw new \Exception('حسابك كتقني لم يتم اعتماده بعد من الإدارة');
        }

        $maintenanceRequest = MaintenanceRequest::find($requestId);

        if (!$maintenanceRequest) {
            throw new \Exception('الطلب غير موجود');
        }
        if (!in_array($maintenanceRequest->status, ['pending', 'quoted'])) {
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

            if ($maintenanceRequest->quotations()->count() === 1) {
                $maintenanceRequest->update(['status' => 'quoted']);
            }

            DB::commit();

            $quotation = $quotation->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $customer = $maintenanceRequest->user;
        if ($customer && $customer->id !== $user->id) {
            $this->notifications->notifyUser(
                $customer,
                'maintenance_quotation_received',
                'تم استلام عرض سعر جديد',
                'تلقيت عرض سعر جديد على طلب الصيانة الخاص بك',
                [
                    'entity_type' => 'maintenance_request',
                    'entity_id' => $maintenanceRequest->id,
                    'action' => 'open_details',
                    'status' => 'quoted',
                    'quotation_id' => $quotation->id,
                    'technician_id' => $technician->user_id,
                    'price' => $quotation->price,
                ]
            );
        }

        return $quotation;
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

        return ServiceJob::where('technician_id', $technician->user_id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->with([
                'maintenanceRequest' => function ($q) {
                    $q->with(['user', 'vehicle']);
                },
                'maintenanceRecord'
            ])
            ->latest()
            ->get();
    }



    public function getJobDetails(User $user, int $jobId): ServiceJob
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $job = ServiceJob::where('id', $jobId)
            ->where('technician_id', $technician->user_id)
            ->with([
                'maintenanceRequest' => function ($q) {
                    $q->with(['user', 'vehicle']);
                },
                'maintenanceRecord'
            ])
            ->first();

        if (!$job) {
            throw new \Exception('المهمة غير موجودة أو لا تخصك');
        }

        return $job;
    }

    public function updateJobStatus(User $user, int $jobId, array $data): ServiceJob
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $newStatus = $data['status'];

        // فقط الانتقالات التالية مسموحة اعتماداً على الحالة الفعلية المقفلة للسجل:
        // assigned -> in_progress، و(assigned أو in_progress) -> completed. أي تكرار لنفس الحالة أو الخروج من completed مرفوض.
        $allowedFrom = [
            'in_progress' => ['assigned'],
            'completed' => ['assigned', 'in_progress'],
        ];

        $job = DB::transaction(function () use ($jobId, $technician, $newStatus, $data, $allowedFrom) {
            $job = ServiceJob::where('id', $jobId)
                ->where('technician_id', $technician->user_id)
                ->lockForUpdate()
                ->first();

            if (!$job) {
                throw new \Exception('المهمة غير موجودة أو لا تخصك');
            }

            if (!isset($allowedFrom[$newStatus]) || !in_array($job->status, $allowedFrom[$newStatus], true)) {
                throw new \Exception('لا يمكن تحديث حالة المهمة في وضعها الحالي');
            }

            $job->status = $newStatus;

            if ($newStatus === 'in_progress' && !$job->started_at) {
                $job->started_at = now();
            }

            if ($newStatus === 'completed' && !$job->completed_at) {
                $job->completed_at = now();
            }

            $job->save();

            $maintenanceRequest = $job->maintenanceRequest;
            if ($maintenanceRequest) {
                $maintenanceRequest->status = $newStatus === 'completed' ? 'completed' : 'in_progress';
                $maintenanceRequest->save();
            }

            if ($newStatus === 'completed') {
                MaintenanceRecord::create([
                    'service_job_id' => $job->id,
                    'vehicle_id' => $maintenanceRequest->vehicle_id,
                    'maintenance_request_id' => $maintenanceRequest->id,
                    'details' => $data['completion_notes'] ?? 'تم إنجاز الصيانة',
                    'parts_used' => $data['parts_used'] ?? null,
                    'completion_notes' => $data['completion_notes'] ?? null,
                    'recommendations' => $data['recommendations'] ?? null,
                    'completed_at' => $job->completed_at,
                ]);
            }

            return $job->fresh(['maintenanceRequest', 'maintenanceRecord']);
        });

        $customer = $job->maintenanceRequest?->user;
        if ($customer && $customer->id !== $user->id) {
            if ($newStatus === 'in_progress') {
                $this->notifications->notifyUser(
                    $customer,
                    'maintenance_job_in_progress',
                    'بدأ تنفيذ الصيانة',
                    'بدأ الفني تنفيذ خدمة الصيانة الخاصة بمركبتك',
                    [
                        'entity_type' => 'maintenance_request',
                        'entity_id' => $job->maintenance_request_id,
                        'action' => 'open_details',
                        'status' => 'in_progress',
                        'service_job_id' => $job->id,
                        'technician_id' => $user->id,
                    ]
                );
            } elseif ($newStatus === 'completed') {
                $this->notifications->notifyUser(
                    $customer,
                    'maintenance_job_completed',
                    'تم إنجاز الصيانة',
                    'تم إنجاز صيانة مركبتك بنجاح',
                    [
                        'entity_type' => 'maintenance_request',
                        'entity_id' => $job->maintenance_request_id,
                        'action' => 'open_details',
                        'status' => 'completed',
                        'service_job_id' => $job->id,
                        'technician_id' => $user->id,
                    ]
                );
            }
        }

        return $job;
    }

    public function addMaintenanceRecord(User $user, int $jobId, array $data): MaintenanceRecord
    {
        $technician = $user->technician;

        if (!$technician) {
            throw new \Exception('أنت لست تقنياً');
        }

        $job = ServiceJob::where('id', $jobId)
            ->where('technician_id', $technician->user_id)
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
