<?php

namespace App\Repositories\Implementation;

use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Repositories\Contracts\MaintenanceRequestRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class MaintenanceRequestRepository implements MaintenanceRequestRepositoryInterface
{
    public function __construct(
        protected MaintenanceRequest $model
    ) {}

    public function find(int $id): ?MaintenanceRequest
    {
        return $this->model->with(['vehicle', 'photos', 'quotations.technician'])->find($id);
    }

    public function getUserRequests(User $user, string $status = null): LengthAwarePaginator
    {
        $query = $user->maintenanceRequests()
            ->with(['vehicle', 'photos'])
            ->latest();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate(15);
    }

    public function getPendingRequests(User $user): Collection
    {
        return $user->maintenanceRequests()
            ->whereIn('status', ['pending', 'quoted'])
            ->with(['vehicle', 'quotations'])
            ->latest()
            ->get();
    }

    public function getAcceptedRequests(User $user): Collection
    {
        return $user->maintenanceRequests()
            ->whereIn('status', ['quotation_accepted', 'in_progress', 'completed'])
            ->with(['vehicle', 'quotations' => function($q) {
                $q->where('status', 'accepted')->with('technician');
            }])
            ->latest()
            ->get();
    }

    public function createForUser(User $user, array $data): MaintenanceRequest
    {
        return $user->maintenanceRequests()->create($data);
    }

    public function update(MaintenanceRequest $request, array $data): bool
    {
        return $request->update($data);
    }

    public function cancel(MaintenanceRequest $request, string $reason): bool
    {
        return $request->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ]);
    }

    public function delete(MaintenanceRequest $request): bool
    {
        return $request->delete();
    }
}