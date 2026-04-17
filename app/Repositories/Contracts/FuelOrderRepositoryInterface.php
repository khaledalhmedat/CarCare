<?php

namespace App\Repositories\Contracts;

use App\Models\FuelOrder;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface FuelOrderRepositoryInterface
{
    public function find(int $id): ?FuelOrder;
    public function getUserOrders(User $user, ?string $status = null): LengthAwarePaginator;
    public function createForUser(User $user, array $data): FuelOrder;
    public function update(FuelOrder $order, array $data): bool;
    public function cancel(FuelOrder $order, string $reason): bool;
    public function assignProvider(FuelOrder $order, int $providerId): bool;
    public function updateStatus(FuelOrder $order, string $status): bool;
}