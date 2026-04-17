<?php

namespace App\Services;

use App\Models\User;
use App\Models\Role;
use App\Models\CarWasher;
use App\Repositories\Contracts\CarWasherRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CarWasherService
{
    public function __construct(
        protected CarWasherRepositoryInterface $repository
    ) {}

    public function getProfile(User $user): ?CarWasher
    {
        return $this->repository->findByUserId($user->id);
    }


    public function createOrUpdateProfile(User $user, array $data): CarWasher
    {
        try {
            DB::beginTransaction();

            $carWasher = $this->repository->findByUserId($user->id);

            if ($carWasher) {
                $this->repository->update($carWasher, $data);
            } else {
                $carWasher = $this->repository->createForUser($user, $data);

                $role = Role::where('slug', 'car-washer')->first();
                if ($role) {
                    $user->roles()->attach($role->id);
                }
            }

            DB::commit();
            return $carWasher->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }


    public function uploadLogo(User $user, $logoFile): CarWasher
    {
        $carWasher = $this->repository->findByUserId($user->id);

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        if ($carWasher->logo) {
            Storage::disk('public')->delete($carWasher->logo);
        }

        $path = $logoFile->store('car-washers', 'public');

        $this->repository->update($carWasher, ['logo' => $path]);

        return $carWasher->fresh();
    }


    public function deleteLogo(User $user): bool
    {
        $carWasher = $this->repository->findByUserId($user->id);

        if (!$carWasher || !$carWasher->logo) {
            throw new \Exception('لا يوجد شعار للحذف');
        }

        Storage::disk('public')->delete($carWasher->logo);

        return $this->repository->update($carWasher, ['logo' => null]);
    }

    public function updateAvailability(User $user, bool $isAvailable): bool
    {
        $carWasher = $this->repository->findByUserId($user->id);

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        return $this->repository->updateAvailability($carWasher, $isAvailable);
    }

    public function getStatistics(User $user): array
    {
        $carWasher = $this->repository->findByUserId($user->id);

        if (!$carWasher) {
            throw new \Exception('لم تقم بإدخال معلومات مغسلتك بعد');
        }

        $bookings = $carWasher->bookings();

        return [
            'total_bookings' => $bookings->count(),
            'pending_bookings' => (clone $bookings)->where('status', 'pending')->count(),
            'accepted_bookings' => (clone $bookings)->where('status', 'accepted')->count(),
            'in_progress_bookings' => (clone $bookings)->where('status', 'in_progress')->count(),
            'completed_bookings' => (clone $bookings)->where('status', 'completed')->count(),
            'cancelled_bookings' => (clone $bookings)->where('status', 'cancelled')->count(),
            'average_rating' => round($carWasher->average_rating, 2),
            'ratings_count' => $carWasher->ratings_count,
        ];
    }
}
