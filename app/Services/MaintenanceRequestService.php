<?php

namespace App\Services;

use App\Models\User;
use App\Models\Technician;
use App\Models\MaintenanceRequest;
use App\Models\Quotation;
use App\Models\ServiceJob;
use App\Models\Rating;
use App\Repositories\Contracts\MaintenanceRequestRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaintenanceRequestService
{
    public function __construct(
        protected MaintenanceRequestRepositoryInterface $requestRepository,
        protected NotificationService $notifications
    ) {}

    private function notifyQuotationAccepted(MaintenanceRequest $request, Quotation $quotation, User $actor, ?int $serviceJobId, ?string $scheduledDate): void
    {
        $technicianUser = $quotation->technician;

        if (!$technicianUser || $technicianUser->id === $actor->id) {
            return;
        }

        $this->notifications->notifyUser(
            $technicianUser,
            'maintenance_quotation_accepted',
            'تم قبول عرض السعر',
            'تم قبول عرض السعر الخاص بك',
            [
                'entity_type' => 'maintenance_request',
                'entity_id' => $request->id,
                'action' => 'open_details',
                'status' => 'quotation_accepted',
                'quotation_id' => $quotation->id,
                'service_job_id' => $serviceJobId,
                'scheduled_date' => $scheduledDate,
            ]
        );
    }

    private function lockRequestAndQuotations(int $requestId, int $quotationId, User $user): array
    {
        $lockedRequest = MaintenanceRequest::where('id', $requestId)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (!$lockedRequest) {
            throw new \Exception('الطلب غير موجود أو لا تملك صلاحية الوصول إليه');
        }

        if ($lockedRequest->status !== 'quoted') {
            throw new \Exception('لا يمكن قبول العروض الآن');
        }

        $lockedQuotations = Quotation::where('maintenance_request_id', $lockedRequest->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lockedQuotation = $lockedQuotations->firstWhere('id', $quotationId);

        if (!$lockedQuotation) {
            throw new \Exception('العرض غير موجود');
        }

        if ($lockedQuotation->status !== 'pending') {
            throw new \Exception('هذا العرض غير متاح للقبول');
        }

        return [$lockedRequest, $lockedQuotation, $lockedQuotations];
    }

    private function notifyLosingQuotations(MaintenanceRequest $request, $losingQuotations, ?int $acceptedTechnicianId, User $actor): void
    {
        $notifiedTechnicianIds = [];

        foreach ($losingQuotations as $losing) {
            $technicianId = $losing->technician_id;

            if (!$technicianId || $technicianId === $acceptedTechnicianId || $technicianId === $actor->id) {
                continue;
            }

            if (in_array($technicianId, $notifiedTechnicianIds, true)) {
                continue;
            }

            $technicianUser = User::find($technicianId);
            if (!$technicianUser) {
                continue;
            }

            $notifiedTechnicianIds[] = $technicianId;

            $this->notifications->notifyUser(
                $technicianUser,
                'maintenance_quotation_rejected',
                'تم رفض عرض السعر',
                'تم اختيار عرض سعر آخر لطلب الصيانة',
                [
                    'entity_type' => 'maintenance_request',
                    'entity_id' => $request->id,
                    'action' => 'open_details',
                    'status' => 'rejected',
                    'quotation_id' => $losing->id,
                    'rejection_source' => 'another_quotation_accepted',
                ]
            );
        }
    }


    public function getUserRequests(User $user, ?string $status = null)
    {
        return $this->requestRepository->getUserRequests($user, $status);
    }

    public function getRequest(int $id, User $user): MaintenanceRequest
    {
        $request = $this->requestRepository->find($id);

        if (!$request || $request->user_id !== $user->id) {
            throw new \Exception('الطلب غير موجود أو لا تملك صلاحية الوصول إليه');
        }

        return $request;
    }

    public function createRequest(User $user, array $data): MaintenanceRequest
    {
        try {
            DB::beginTransaction();

            $vehicle = $user->vehicles()->find($data['vehicle_id']);
            if (!$vehicle) {
                throw new \Exception('المركبة غير موجودة أو لا تخصك');
            }

            $request = $this->requestRepository->createForUser($user, [
                'vehicle_id' => $data['vehicle_id'],
                'description' => $data['description'],
                'priority' => $data['priority'],
                'preferred_date' => $data['preferred_date'] ?? null,
                'status' => 'pending',
            ]);

            if (isset($data['images']) && !empty($data['images'])) {
                foreach ($data['images'] as $image) {
                    $path = $image->store('maintenance-requests/' . $request->id, 'public');
                    $request->photos()->create(['path' => $path]);
                }
            }

            DB::commit();
            return $request->load(['vehicle', 'photos']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateRequest(int $id, User $user, array $data): MaintenanceRequest
    {
        $request = $this->getRequest($id, $user);

        if ($request->status !== 'pending') {
            throw new \Exception('لا يمكن تعديل الطلب بعد استلام عروض');
        }

        $this->requestRepository->update($request, $data);

        return $request->fresh(['vehicle', 'photos']);
    }

    public function cancelRequest(int $id, User $user, string $reason): bool
    {
        $request = $this->getRequest($id, $user);

        if (!in_array($request->status, ['pending', 'quoted'])) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }

        return $this->requestRepository->cancel($request, $reason);
    }

    public function deleteRequest(int $id, User $user): bool
    {
        $request = $this->getRequest($id, $user);

        if ($request->status !== 'pending') {
            throw new \Exception('لا يمكن حذف الطلب بعد استلام عروض');
        }

        foreach ($request->photos as $photo) {
            Storage::disk('public')->delete($photo->path);
            $photo->delete();
        }

        return $this->requestRepository->delete($request);
    }

    public function getPendingRequests(User $user)
    {
        return $user->maintenanceRequests()
            ->whereIn('status', ['pending', 'quoted'])
            ->with(['vehicle', 'quotations'])
            ->latest()
            ->get();
    }

    public function getAcceptedRequests(User $user)
    {
        return $user->maintenanceRequests()
            ->whereIn('status', ['quotation_accepted', 'in_progress'])
            ->with(['vehicle', 'quotations' => function ($q) {
                $q->where('status', 'accepted')->with('technician');
            }])
            ->latest()
            ->get();
    }

    public function getCompletedRequests(User $user)
    {
        return $user->maintenanceRequests()
            ->where('status', 'completed')
            // serviceJob.maintenanceRecord is read by the resource for completed requests;
            // eager-load it to avoid an N+1 across the list
            ->with(['vehicle', 'maintenanceRecord', 'serviceJob.maintenanceRecord'])
            ->latest()
            ->get();
    }

    public function acceptQuotation(int $requestId, int $quotationId, User $user): bool
    {
        $this->getRequest($requestId, $user);

        $result = DB::transaction(function () use ($requestId, $quotationId, $user) {
            [$lockedRequest, $lockedQuotation, $lockedQuotations] = $this->lockRequestAndQuotations($requestId, $quotationId, $user);

            $losingQuotations = $lockedQuotations
                ->where('id', '!=', $lockedQuotation->id)
                ->where('status', 'pending')
                ->values();

            Quotation::where('maintenance_request_id', $lockedRequest->id)
                ->where('id', '!=', $lockedQuotation->id)
                ->update(['status' => 'rejected']);

            $lockedQuotation->update(['status' => 'accepted']);

            $lockedRequest->update(['status' => 'quotation_accepted']);

            return [
                'request' => $lockedRequest,
                'quotation_id' => $lockedQuotation->id,
                'losing_quotations' => $losingQuotations,
            ];
        });

        $request = $result['request'];
        $quotation = Quotation::with('technician')->find($result['quotation_id']);

        $this->notifyQuotationAccepted($request, $quotation, $user, null, null);
        $this->notifyLosingQuotations($request, $result['losing_quotations'], $quotation->technician_id, $user);

        return true;
    }


    public function getRequestQuotations(User $user, int $requestId)
    {
        $request = $this->getRequest($requestId, $user);

        return $request->quotations()
            ->with(['technician.technician'])
            ->latest()
            ->get();
    }


    public function rejectQuotation(User $user, int $quotationId, ?string $reason = null): bool
    {
        $quotation = Quotation::with('maintenanceRequest')->find($quotationId);

        if (!$quotation) {
            throw new \Exception('العرض غير موجود');
        }

        if ($quotation->maintenanceRequest->user_id !== $user->id) {
            throw new \Exception('لا تملك صلاحية الوصول لهذا الطلب');
        }

        if ($quotation->status !== 'pending') {
            throw new \Exception('لا يمكن رفض هذا العرض حالياً');
        }

        DB::transaction(function () use ($quotation, $reason) {
            $quotation->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_at' => now(),
            ]);

            $pendingCount = Quotation::where('maintenance_request_id', $quotation->maintenance_request_id)
                ->where('status', 'pending')
                ->count();

            if ($pendingCount === 0) {
                $quotation->maintenanceRequest->update([
                    'status' => 'pending',
                ]);
            }
        });

        $technicianUser = $quotation->technician;
        if ($technicianUser && $technicianUser->id !== $user->id) {
            $this->notifications->notifyUser(
                $technicianUser,
                'maintenance_quotation_rejected',
                'تم رفض عرض السعر',
                'تم رفض عرض السعر الذي قدمته',
                [
                    'entity_type' => 'maintenance_request',
                    'entity_id' => $quotation->maintenance_request_id,
                    'action' => 'open_details',
                    'status' => 'rejected',
                    'quotation_id' => $quotation->id,
                    'rejection_source' => 'explicit',
                    'reason' => $reason,
                ]
            );
        }

        return true;
    }


    public function getAcceptedQuotation(User $user, int $requestId): ?Quotation
    {
        $request = $this->getRequest($requestId, $user);

        return $request->quotations()
            ->where('status', 'accepted')
            ->with(['technician.technician', 'serviceJob'])
            ->first();
    }


    public function rateService(User $user, int $jobId, array $data): Rating
    {
        $job = ServiceJob::with('maintenanceRequest')
            ->where('id', $jobId)
            ->first();

        if (!$job) {
            throw new \Exception('المهمة غير موجودة');
        }

        if ($job->maintenanceRequest->user_id !== $user->id) {
            throw new \Exception('لا تملك صلاحية تقييم هذه المهمة');
        }

        if ($job->status !== 'completed') {
            throw new \Exception('لا يمكن التقييم إلا بعد إنجاز المهمة');
        }

        $existingRating = Rating::where('service_job_id', $jobId)->first();
        if ($existingRating) {
            throw new \Exception('لقد قيمت هذه المهمة مسبقاً');
        }

        return DB::transaction(function () use ($user, $job, $jobId, $data) {
            $rating = Rating::create([
                'user_id' => $user->id,
                'service_job_id' => $jobId,
                'technician_id' => $job->technician_id,
                'rating' => $data['rating'],
                'review' => $data['review'] ?? null,
            ]);

            $technician = Technician::where('user_id', $job->technician_id)->first();
            if ($technician) {
                $averageRating = Rating::where('technician_id', $job->technician_id)->avg('rating');
                $ratingsCount = Rating::where('technician_id', $job->technician_id)->count();

                $technician->update([
                    'average_rating' => $averageRating,
                    'ratings_count' => $ratingsCount,
                ]);
            }

            return $rating;
        });
    }


    public function acceptQuotationWithSchedule(int $requestId, int $quotationId, User $user, array $data): array
    {
        // فحص سريع اختياري فقط لرسالة خطأ مبكرة؛ التحقق الموثوق يتم داخل القفل أدناه
        $this->getRequest($requestId, $user);

        $result = DB::transaction(function () use ($requestId, $quotationId, $user, $data) {
            [$lockedRequest, $lockedQuotation, $lockedQuotations] = $this->lockRequestAndQuotations($requestId, $quotationId, $user);

            $losingQuotations = $lockedQuotations
                ->where('id', '!=', $lockedQuotation->id)
                ->where('status', 'pending')
                ->values();

            Quotation::where('maintenance_request_id', $lockedRequest->id)
                ->where('id', '!=', $lockedQuotation->id)
                ->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                ]);

            $lockedQuotation->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            $lockedRequest->update(['status' => 'quotation_accepted']);

            $serviceJob = ServiceJob::create([
                'maintenance_request_id' => $lockedRequest->id,
                'quotation_id' => $lockedQuotation->id,
                'technician_id' => $lockedQuotation->technician_id,
                'status' => 'assigned',
                'scheduled_date' => $data['scheduled_date'] ?? now()->addDays(1),
                'notes' => $data['notes'] ?? null,
            ]);

            return [
                'request' => $lockedRequest,
                'quotation_id' => $lockedQuotation->id,
                'service_job' => $serviceJob,
                'losing_quotations' => $losingQuotations,
            ];
        });

        $request = $result['request'];
        $quotation = Quotation::with('technician')->find($result['quotation_id']);

        $this->notifyQuotationAccepted(
            $request,
            $quotation,
            $user,
            $result['service_job']->id,
            $result['service_job']->scheduled_date->toDateString()
        );
        $this->notifyLosingQuotations($request, $result['losing_quotations'], $quotation->technician_id, $user);

        return [
            'quotation' => $quotation,
            'service_job' => $result['service_job'],
        ];
    }


    public function reopenRequest(int $id, User $user): bool
    {
        $request = $this->getRequest($id, $user);

        if ($request->status !== 'quoted') {
            throw new \Exception('لا يمكن إعادة فتح الطلب في هذه المرحلة');
        }

        $pendingCount = $request->quotations()->where('status', 'pending')->count();

        if ($pendingCount > 0) {
            throw new \Exception('لا يمكن إعادة فتح الطلب وفي عروض معلقة');
        }

        return $request->update([
            'status' => 'pending',
        ]);
    }
}
