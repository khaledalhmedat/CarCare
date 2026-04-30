<?php

namespace App\Services;

use App\Models\User;
use App\Models\SosRequest;
use App\Repositories\Contracts\SosRepositoryInterface;
use App\Events\NewSosRequest;
use Illuminate\Support\Facades\DB;

class SosService
{
    public function __construct(protected SosRepositoryInterface $repository) {}

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

    public function createRequest(User $user, array $data): SosRequest
    {
        try {
            DB::beginTransaction();

            $vehicle = $user->vehicles()->find($data['vehicle_id']);
            if (!$vehicle) {
                throw new \Exception('المركبة غير موجودة');
            }

            $sosRequest = $this->repository->createForUser($user, [
                'vehicle_id' => $data['vehicle_id'],
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'description' => $data['description'] ?? null,
                'status' => 'open',
                'priority' => 'emergency',
            ]);

            broadcast(new NewSosRequest($sosRequest));

            DB::commit();
            return $sosRequest->load(['vehicle']);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function cancelRequest(int $id, User $user, string $reason): bool
    {
        $request = $this->getRequest($id, $user);
        if (!in_array($request->status, ['open', 'accepted'])) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }
        return $this->repository->cancel($request, $reason);
    }
}