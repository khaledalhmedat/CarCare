<?php

namespace App\Repositories\Contracts;

use App\Models\CarWasher;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

interface CarWasherRepositoryInterface
{
    public function find(int $id): ?CarWasher;
    public function findByUserId(int $userId): ?CarWasher;
    public function getAvailableCarWashers(array $filters = []): LengthAwarePaginator;
    public function createForUser(User $user, array $data): CarWasher;
    public function update(CarWasher $carWasher, array $data): bool;
    public function updateAvailability(CarWasher $carWasher, bool $isAvailable): bool;
    public function updateRating(CarWasher $carWasher): void;
}