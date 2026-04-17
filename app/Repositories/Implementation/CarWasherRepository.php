<?php

namespace App\Repositories\Implementation;

use App\Models\CarWasher;
use App\Models\User;
use App\Repositories\Contracts\CarWasherRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class CarWasherRepository implements CarWasherRepositoryInterface
{
    public function __construct(protected CarWasher $model) {}

    public function find(int $id): ?CarWasher
    {
        return $this->model->with('user')->find($id);
    }

    public function findByUserId(int $userId): ?CarWasher
    {
        return $this->model->where('user_id', $userId)->first();
    }

    public function getAvailableCarWashers(array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->where('is_available', true)
            ->where('is_verified', true)
            ->with('user');

        if (isset($filters['city'])) {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }

        if (isset($filters['service'])) {
            $query->whereJsonContains('services', $filters['service']);
        }

        return $query->latest()->paginate($filters['per_page'] ?? 15);
    }

    public function createForUser(User $user, array $data): CarWasher
    {
        $data['user_id'] = $user->id;
        return $this->model->create($data);
    }

    public function update(CarWasher $carWasher, array $data): bool
    {
        return $carWasher->update($data);
    }

    public function updateAvailability(CarWasher $carWasher, bool $isAvailable): bool
    {
        return $carWasher->update(['is_available' => $isAvailable]);
    }

    public function updateRating(CarWasher $carWasher): void
    {
        $averageRating = $carWasher->ratings()->avg('rating') ?? 0;
        $ratingsCount = $carWasher->ratings()->count();

        $carWasher->update([
            'average_rating' => round($averageRating, 2),
            'ratings_count' => $ratingsCount,
        ]);
    }
}
