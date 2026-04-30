<?php

namespace App\Repositories\Implementation;

use App\Models\SosRequest;
use App\Models\User;
use App\Repositories\Contracts\SosRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class SosRepository implements SosRepositoryInterface
{
    public function __construct(protected SosRequest $model) {}

    public function find(int $id): ?SosRequest
    {
        return $this->model->with(['user', 'vehicle', 'technician'])->find($id);
    }

    public function getUserRequests(User $user, ?string $status = null): LengthAwarePaginator
    {
        $query = $user->sosRequests()->with(['vehicle'])->latest();
        if ($status) {
            $query->where('status', $status);
        }
        return $query->paginate(15);
    }

    public function createForUser(User $user, array $data): SosRequest
    {
        return $user->sosRequests()->create($data);
    }

    public function update(SosRequest $request, array $data): bool
    {
        return $request->update($data);
    }

    public function cancel(SosRequest $request, string $reason): bool
    {
        return $request->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
        ]);
    }

    public function assignTechnician(SosRequest $request, int $technicianId): bool
    {
        return $request->update([
            'technician_id' => $technicianId,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function complete(SosRequest $request): bool
    {
        return $request->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}