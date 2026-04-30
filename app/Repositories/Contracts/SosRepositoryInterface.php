<?php

namespace App\Repositories\Contracts;

use App\Models\SosRequest;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface SosRepositoryInterface
{
    public function find(int $id): ?SosRequest;
    public function getUserRequests(User $user, ?string $status = null): LengthAwarePaginator;
    public function createForUser(User $user, array $data): SosRequest;
    public function update(SosRequest $request, array $data): bool;
    public function cancel(SosRequest $request, string $reason): bool;
    public function assignTechnician(SosRequest $request, int $technicianId): bool;
    public function complete(SosRequest $request): bool;
}