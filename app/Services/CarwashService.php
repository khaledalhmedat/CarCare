<?php

namespace App\Services;

use App\Models\User;
use App\Models\CarwashBooking;
use App\Models\CarWashRating;
use App\Models\CarWasher;
use App\Repositories\Contracts\CarwashRepositoryInterface;
use Illuminate\Support\Facades\DB;

class CarwashService
{
    public function __construct(
        protected CarwashRepositoryInterface $repository
    ) {}


    public function getAvailableCarWashers(array $filters = [])
    {
        $query = CarWasher::where('is_available', true)
            // ->where('is_verified', true)
            ->with('user');

        if (isset($filters['city'])) {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }

        if (isset($filters['service'])) {
            $query->whereJsonContains('services', $filters['service']);
        }

        return $query->latest()->paginate(15);
    }


    public function getCarWasherDetails(int $id): ?CarWasher
    {
        return CarWasher::with('user')->find($id);
    }


    public function getUserBookings(User $user, ?string $status = null)
    {
        return $this->repository->getUserBookings($user, $status);
    }


    public function getBooking(int $id, User $user): CarwashBooking
    {
        $booking = $this->repository->find($id);

        if (!$booking || $booking->user_id !== $user->id) {
            throw new \Exception('الحجز غير موجود أو لا تملك صلاحية الوصول إليه');
        }

        return $booking;
    }


    public function createBooking(User $user, array $data): CarwashBooking
    {
        try {
            DB::beginTransaction();

            $carWasher = CarWasher::find($data['car_washer_id']);

            if (!$carWasher) {
                throw new \Exception('المغسلة غير موجودة');
            }

            if (!$carWasher->is_available) {
                throw new \Exception('المغسلة غير متاحة حالياً');
            }

            $vehicle = $user->vehicles()->find($data['vehicle_id']);
            if (!$vehicle) {
                throw new \Exception('المركبة غير موجودة أو لا تخصك');
            }

            $booking = $this->repository->createForUser($user, $data);

            DB::commit();
            return $booking->load(['vehicle', 'carWasher']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function cancelBooking(int $id, User $user, string $reason): bool
    {
        $booking = $this->getBooking($id, $user);

        if (!in_array($booking->status, ['pending', 'accepted'])) {
            throw new \Exception('لا يمكن إلغاء الحجز في هذه المرحلة');
        }

        return $this->repository->cancel($booking, $reason);
    }


    public function acceptBooking(User $user, int $bookingId): CarwashBooking
    {
        $carWasher = $user->carWasher;

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        $booking = $this->repository->find($bookingId);

        if (!$booking || $booking->car_washer_id !== $carWasher->id) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($booking->status !== 'pending') {
            throw new \Exception('هذا الطلب غير متاح للقبول');
        }

        $this->repository->accept($booking);

        return $booking->fresh();
    }


    public function rejectBooking(User $user, int $bookingId, ?string $reason = null): CarwashBooking
    {
        $carWasher = $user->carWasher;

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        $booking = $this->repository->find($bookingId);

        if (!$booking || $booking->car_washer_id !== $carWasher->id) {
            throw new \Exception('الطلب غير موجود');
        }

        if ($booking->status !== 'pending') {
            throw new \Exception('لا يمكن رفض هذا الطلب حالياً');
        }

        $this->repository->reject($booking, $reason);

        return $booking->fresh();
    }


    public function getCarWasherBookings(User $user, ?string $status = null)
    {
        $carWasher = $user->carWasher;

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        return $this->repository->getCarWasherBookings($carWasher->id, $status);
    }


    public function updateBookingStatus(User $user, int $bookingId, string $status): CarwashBooking
    {
        $carWasher = $user->carWasher;

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        $booking = $this->repository->find($bookingId);

        if (!$booking || $booking->car_washer_id !== $carWasher->id) {
            throw new \Exception('الطلب غير موجود');
        }

        if (!in_array($status, ['in_progress', 'completed'])) {
            throw new \Exception('الحالة غير صحيحة');
        }

        $this->repository->updateStatus($booking, $status);

        return $booking->fresh();
    }


    public function rateCarWasher(User $user, int $bookingId, array $data): CarWashRating
    {
        $booking = $this->getBooking($bookingId, $user);

        if ($booking->status !== 'completed') {
            throw new \Exception('لا يمكن التقييم إلا بعد إكمال الخدمة');
        }

        $existingRating = CarWashRating::where('carwash_booking_id', $bookingId)->first();
        if ($existingRating) {
            throw new \Exception('لقد قيمت هذه الخدمة مسبقاً');
        }

        try {
            DB::beginTransaction();

            $rating = CarWashRating::create([
                'user_id' => $user->id,
                'carwash_booking_id' => $bookingId,
                'car_washer_id' => $booking->car_washer_id,
                'rating' => $data['rating'],
                'review' => $data['review'] ?? null,
            ]);

            $this->updateCarWasherRating($booking->car_washer_id);

            DB::commit();
            return $rating;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    private function updateCarWasherRating(int $carWasherId): void
    {
        $averageRating = CarWashRating::where('car_washer_id', $carWasherId)->avg('rating');
        $ratingsCount = CarWashRating::where('car_washer_id', $carWasherId)->count();

        CarWasher::where('id', $carWasherId)->update([
            'average_rating' => round($averageRating, 2),
            'ratings_count' => $ratingsCount,
        ]);
    }


    public function getCarWasherRatings(int $carWasherId, int $perPage = 10)
    {
        return CarWashRating::where('car_washer_id', $carWasherId)
            ->with(['user'])
            ->latest()
            ->paginate($perPage);
    }
}
