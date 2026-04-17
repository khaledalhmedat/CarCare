<?php

namespace App\Repositories\Contracts;

use App\Models\CarwashBooking;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface CarwashRepositoryInterface
{
    public function find(int $id): ?CarwashBooking;
    public function getUserBookings(User $user, ?string $status = null): LengthAwarePaginator;
    public function getPendingBookings(): Collection;
    public function getBookingsByCarWasher(int $carWasherId, ?string $status = null): LengthAwarePaginator;
    public function createForUser(User $user, array $data): CarwashBooking;
    public function update(CarwashBooking $booking, array $data): bool;
    public function cancel(CarwashBooking $booking, string $reason): bool;
    public function assignCarWasher(CarwashBooking $booking, int $carWasherId): bool;
    public function updateStatus(CarwashBooking $booking, string $status): bool;
    public function delete(CarwashBooking $booking): bool;

    public function accept(CarwashBooking $booking): bool;
    public function reject(CarwashBooking $booking, ?string $reason = null): bool;
    public function getCarWasherBookings(int $carWasherId, ?string $status = null): LengthAwarePaginator;
}
