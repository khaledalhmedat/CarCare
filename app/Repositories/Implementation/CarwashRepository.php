<?php

namespace App\Repositories\Implementation;

use App\Models\CarwashBooking;
use App\Models\User;
use App\Repositories\Contracts\CarwashRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CarwashRepository implements CarwashRepositoryInterface
{
    public function __construct(
        protected CarwashBooking $model
    ) {}

    public function find(int $id): ?CarwashBooking
    {
        return $this->model
            ->with(['user', 'vehicle', 'carWasher.user'])
            ->find($id);
    }


    public function getUserBookings(User $user, ?string $status = null): LengthAwarePaginator
    {
        $query = $user->carwashBookings()
            ->with(['vehicle', 'carWasher'])
            ->latest('scheduled_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate(15);
    }


    public function getPendingBookings(): Collection
    {
        return $this->model
            ->with(['user', 'vehicle'])
            ->where('status', 'pending')
            ->where('scheduled_at', '>', now())
            ->orderBy('scheduled_at', 'asc')
            ->get();
    }


    public function getBookingsByCarWasher(int $carWasherId, ?string $status = null): LengthAwarePaginator
    {
        $query = $this->model
            ->with(['user', 'vehicle'])
            ->where('car_washer_id', $carWasherId)
            ->latest('scheduled_at');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate(15);
    }


    public function createForUser(User $user, array $data): CarwashBooking
    {
        return $user->carwashBookings()->create($data);
    }


    public function update(CarwashBooking $booking, array $data): bool
    {
        return $booking->update($data);
    }


    public function cancel(CarwashBooking $booking, string $reason): bool
    {
        return $booking->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'completed_at' => now(),
        ]);
    }


    public function assignCarWasher(CarwashBooking $booking, int $carWasherId): bool
    {
        return $booking->update([
            'car_washer_id' => $carWasherId,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }


    public function updateStatus(CarwashBooking $booking, string $status): bool
    {
        $data = ['status' => $status];

        if ($status === 'in_progress') {
            $data['started_at'] = now();
        }

        if ($status === 'completed') {
            $data['completed_at'] = now();
        }

        return $booking->update($data);
    }


    public function delete(CarwashBooking $booking): bool
    {
        return $booking->delete();
    }


    public function accept(CarwashBooking $booking): bool
    {
        return $booking->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }


    public function reject(CarwashBooking $booking, ?string $reason = null): bool
    {
        return $booking->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason ?? 'تم رفض الطلب من قبل المغسلة',
            'completed_at' => now(),
        ]);
    }


    public function getCarWasherBookings(int $carWasherId, ?string $status = null): LengthAwarePaginator
    {
        $query = $this->model->where('car_washer_id', $carWasherId)
            ->with(['user', 'vehicle']);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->latest('scheduled_at')->paginate(15);
    }
}
