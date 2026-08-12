<?php

namespace App\Services;

use App\Models\User;
use App\Models\CarwashBooking;
use App\Models\CarWashRating;
use App\Models\CarWasher;
use App\Repositories\Contracts\CarwashRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CarwashService
{
    public function __construct(
        protected CarwashRepositoryInterface $repository,
        protected NotificationService $notifications
    ) {}

    private function resolveWasherUser(int $carWasherId): ?User
    {
        return CarWasher::find($carWasherId)?->user;
    }


    public function getAvailableCarWashers(array $filters = [])
    {
        $query = CarWasher::where('is_available', true)
            ->where('status', 'approved')
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
        $carWasher = CarWasher::with('user')->find($id);

        if (!$carWasher || $carWasher->status !== 'approved') {
            return null;
        }

        return $carWasher;
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
                throw new NotFoundHttpException('المغسلة غير موجودة');
            }

            if ($carWasher->status !== 'approved') {
                throw ValidationException::withMessages([
                    'car_washer_id' => ['المغسلة غير متاحة للحجز حالياً'],
                ]);
            }

            if ($carWasher->user_id === $user->id) {
                throw ValidationException::withMessages([
                    'car_washer_id' => ['لا يمكنك حجز موعد في مغسلتك الخاصة'],
                ]);
            }

            if (!$carWasher->is_available) {
                throw ValidationException::withMessages([
                    'car_washer_id' => ['المغسلة غير متاحة حالياً'],
                ]);
            }

            $vehicle = $user->vehicles()->find($data['vehicle_id']);
            if (!$vehicle) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['المركبة غير موجودة أو لا تخصك'],
                ]);
            }

            // السعر يُحسب من أسعار المغسلة نفسها ولا يُؤخذ من الطلب أبداً
            $servicePrices = $carWasher->service_prices ?? [];
            if (!array_key_exists($data['service_type'], $servicePrices)) {
                throw ValidationException::withMessages([
                    'service_type' => ['نوع الخدمة غير متوفر لدى هذه المغسلة'],
                ]);
            }

            $data['price'] = $servicePrices[$data['service_type']];
            $data['status'] = CarwashBooking::STATUS_PENDING;

            $booking = $this->repository->createForUser($user, $data);

            DB::commit();
            $booking = $booking->load(['vehicle', 'carWasher']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        $washerUser = $this->resolveWasherUser($carWasher->id);
        if ($washerUser && $washerUser->id !== $user->id) {
            $this->notifications->notifyUser(
                $washerUser,
                'carwash_booking_received',
                'تم استلام طلب غسيل جديد',
                'تلقيت طلب غسيل جديد',
                [
                    'entity_type' => 'carwash_booking',
                    'entity_id' => $booking->id,
                    'action' => 'open_details',
                    'status' => 'pending',
                    'car_washer_id' => $carWasher->id,
                ]
            );
        }

        return $booking;
    }


    public function cancelBooking(int $id, User $user, string $reason): bool
    {
        // فحص سريع اختياري فقط لرسالة خطأ مبكرة؛ التحقق الموثوق يتم داخل القفل أدناه
        $this->getBooking($id, $user);

        $result = DB::transaction(function () use ($id, $user, $reason) {
            $booking = CarwashBooking::where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw new \Exception('الحجز غير موجود أو لا تملك صلاحية الوصول إليه');
            }

            if (!in_array($booking->status, ['pending', 'accepted'])) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن إلغاء الحجز في هذه المرحلة'],
                ]);
            }

            // نلتقط مغسلة الحجز قبل الإلغاء؛ الحجز مُسند لمغسلة منذ الإنشاء (car_washer_id مطلوب حينها)
            $assignedCarWasherId = $booking->car_washer_id;

            $cancelled = $this->repository->cancel($booking, $reason);

            return [
                'cancelled' => $cancelled,
                'booking_id' => $booking->id,
                'car_washer_id' => $assignedCarWasherId,
            ];
        });

        if ($result['cancelled'] && $result['car_washer_id']) {
            $washerUser = $this->resolveWasherUser($result['car_washer_id']);
            if ($washerUser && $washerUser->id !== $user->id) {
                $this->notifications->notifyUser(
                    $washerUser,
                    'carwash_booking_cancelled_by_customer',
                    'تم إلغاء طلب الغسيل',
                    'قام العميل بإلغاء طلب الغسيل',
                    [
                        'entity_type' => 'carwash_booking',
                        'entity_id' => $result['booking_id'],
                        'action' => 'open_details',
                        'status' => 'cancelled',
                        'car_washer_id' => $result['car_washer_id'],
                        'reason' => $reason,
                    ]
                );
            }
        }

        return $result['cancelled'];
    }


    public function acceptBooking(User $user, int $bookingId): CarwashBooking
    {
        $carWasher = $user->carWasher;

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        if ($carWasher->status !== 'approved') {
            throw new \Exception('حسابك كمغسلة لم يتم اعتماده بعد من الإدارة');
        }

        $booking = DB::transaction(function () use ($carWasher, $bookingId) {
            $booking = CarwashBooking::where('id', $bookingId)
                ->where('car_washer_id', $carWasher->id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw new NotFoundHttpException('الطلب غير موجود');
            }

            if ($booking->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['هذا الطلب غير متاح للقبول'],
                ]);
            }

            $this->repository->accept($booking);

            return $booking->fresh();
        });

        $customer = $booking->user;
        if ($customer && $customer->id !== $user->id) {
            $this->notifications->notifyUser(
                $customer,
                'carwash_booking_accepted',
                'تم قبول طلب الغسيل',
                'تم قبول طلب الغسيل الخاص بك',
                [
                    'entity_type' => 'carwash_booking',
                    'entity_id' => $booking->id,
                    'action' => 'open_details',
                    'status' => 'accepted',
                    'car_washer_id' => $carWasher->id,
                ]
            );
        }

        return $booking;
    }


    public function rejectBooking(User $user, int $bookingId, ?string $reason = null): CarwashBooking
    {
        $carWasher = $user->carWasher;

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        $booking = DB::transaction(function () use ($carWasher, $bookingId, $reason) {
            $booking = CarwashBooking::where('id', $bookingId)
                ->where('car_washer_id', $carWasher->id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw new NotFoundHttpException('الطلب غير موجود');
            }

            if ($booking->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن رفض هذا الطلب حالياً'],
                ]);
            }

            $this->repository->reject($booking, $reason);

            return $booking->fresh();
        });

        $customer = $booking->user;
        if ($customer && $customer->id !== $user->id) {
            $this->notifications->notifyUser(
                $customer,
                'carwash_booking_rejected',
                'تم رفض طلب الغسيل',
                'تم رفض طلب الغسيل الخاص بك',
                [
                    'entity_type' => 'carwash_booking',
                    'entity_id' => $booking->id,
                    'action' => 'open_details',
                    'status' => 'cancelled',
                    'car_washer_id' => $carWasher->id,
                    'reason' => $reason,
                ]
            );
        }

        return $booking;
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

        if (!in_array($status, ['in_progress', 'completed'])) {
            throw new \Exception('الحالة غير صحيحة');
        }

        // only allow forward transitions; blocks completing a cancelled/completed/pending booking
        $allowedFrom = ['in_progress' => ['accepted'], 'completed' => ['in_progress']];

        $booking = DB::transaction(function () use ($carWasher, $bookingId, $status, $allowedFrom) {
            $booking = CarwashBooking::where('id', $bookingId)
                ->where('car_washer_id', $carWasher->id)
                ->lockForUpdate()
                ->first();

            if (!$booking) {
                throw new NotFoundHttpException('الطلب غير موجود');
            }

            if (!in_array($booking->status, $allowedFrom[$status], true)) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن تحديث حالة الحجز في وضعه الحالي'],
                ]);
            }

            $this->repository->updateStatus($booking, $status);

            return $booking->fresh();
        });

        $customer = $booking->user;
        if ($customer && $customer->id !== $user->id) {
            if ($status === 'in_progress') {
                $this->notifications->notifyUser(
                    $customer,
                    'carwash_booking_in_progress',
                    'جاري غسيل السيارة',
                    'بدأت المغسلة بغسيل سيارتك',
                    [
                        'entity_type' => 'carwash_booking',
                        'entity_id' => $booking->id,
                        'action' => 'open_details',
                        'status' => 'in_progress',
                        'car_washer_id' => $carWasher->id,
                    ]
                );
            } elseif ($status === 'completed') {
                $this->notifications->notifyUser(
                    $customer,
                    'carwash_booking_completed',
                    'تم إكمال غسيل السيارة',
                    'تم إكمال غسيل سيارتك بنجاح',
                    [
                        'entity_type' => 'carwash_booking',
                        'entity_id' => $booking->id,
                        'action' => 'open_details',
                        'status' => 'completed',
                        'car_washer_id' => $carWasher->id,
                    ]
                );
            }
        }

        return $booking;
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
