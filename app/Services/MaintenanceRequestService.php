<?php

namespace App\Services;

use App\Models\User;
use App\Models\MaintenanceRequest;
use App\Repositories\Contracts\MaintenanceRequestRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MaintenanceRequestService
{
    public function __construct(
        protected MaintenanceRequestRepositoryInterface $requestRepository
    ) {}


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


   /**
 * إنشاء طلب صيانة جديد
 */
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
            ->with(['vehicle', 'quotations' => function($q) {
                $q->where('status', 'accepted')->with('technician');
            }])
            ->latest()
            ->get();
    }

    
    public function getCompletedRequests(User $user)
    {
        return $user->maintenanceRequests()
            ->where('status', 'completed')
            ->with(['vehicle', 'maintenanceRecord'])
            ->latest()
            ->get();
    }

    
    public function acceptQuotation(int $requestId, int $quotationId, User $user): bool
    {
        $request = $this->getRequest($requestId, $user);

        if ($request->status !== 'quoted') {
            throw new \Exception('لا يمكن قبول العروض الآن');
        }

        $quotation = $request->quotations()->find($quotationId);
        
        if (!$quotation) {
            throw new \Exception('العرض غير موجود');
        }

        DB::transaction(function() use ($request, $quotation) {
            $request->quotations()
                ->where('id', '!=', $quotation->id)
                ->update(['status' => 'rejected']);

            $quotation->update(['status' => 'accepted']);

            $request->update(['status' => 'quotation_accepted']);
        });

        return true;
    }
}