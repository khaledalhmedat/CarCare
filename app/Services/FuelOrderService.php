<?php

namespace App\Services;

use App\Models\User;
use App\Models\FuelOrder;
use App\Repositories\Contracts\FuelOrderRepositoryInterface;
use Illuminate\Support\Facades\DB;

class FuelOrderService
{
    public function __construct(protected FuelOrderRepositoryInterface $repository) {}

    public function getUserOrders(User $user, ?string $status = null)
    {
        return $this->repository->getUserOrders($user, $status);
    }

    public function getOrder(int $id, User $user): FuelOrder
    {
        $order = $this->repository->find($id);
        if (!$order || $order->user_id !== $user->id) {
            throw new \Exception('الطلب غير موجود أو لا تملك صلاحية الوصول إليه');
        }
        return $order;
    }

    public function createOrder(User $user, array $data): FuelOrder
    {
        try {
            DB::beginTransaction();
            $order = $this->repository->createForUser($user, $data);
            DB::commit();
            return $order->load(['vehicle']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function cancelOrder(int $id, User $user, string $reason): bool
    {
        $order = $this->getOrder($id, $user);
        if (!$order->canCancel()) {
            throw new \Exception('لا يمكن إلغاء الطلب في هذه المرحلة');
        }
        return $this->repository->cancel($order, $reason);
    }

    public function assignProvider(int $orderId, int $providerId, User $provider): FuelOrder
    {
        $order = $this->repository->find($orderId);
        if (!$order || $order->status !== 'pending') {
            throw new \Exception('الطلب غير متاح للتخصيص');
        }
        $this->repository->assignProvider($order, $providerId);
        return $order->fresh();
    }

    public function updateStatus(int $orderId, string $status, User $provider): FuelOrder
    {
        $order = $this->repository->find($orderId);
        if (!$order || $order->fuel_provider_id !== $provider->fuelProvider?->id) {
            throw new \Exception('لا تملك صلاحية تحديث هذا الطلب');
        }
        $this->repository->updateStatus($order, $status);
        return $order->fresh();
    }
}
