<?php

namespace App\Repositories\Contracts;

use App\Models\MaintenanceRequest;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface MaintenanceRequestRepositoryInterface
{
    public function find(int $id): ?MaintenanceRequest;
    public function getUserRequests(User $user, string $status = null): LengthAwarePaginator;
    public function getPendingRequests(User $user): Collection;
    public function getAcceptedRequests(User $user): Collection;
    public function createForUser(User $user, array $data): MaintenanceRequest;
    public function update(MaintenanceRequest $request, array $data): bool;
    public function cancel(MaintenanceRequest $request, string $reason): bool;
    public function delete(MaintenanceRequest $request): bool;
}