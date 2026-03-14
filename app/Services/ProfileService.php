<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {}


    public function updateProfile(User $user, array $data): User
    {
        $updateData = array_filter($data, function ($value) {
            return !is_null($value) && $value !== '';
        });

        $this->userRepository->update($user, $updateData);

        return $user->fresh();
    }


    public function updatePassword(User $user, string $newPassword): bool
    {
        return $this->userRepository->update($user, [
            'password' => Hash::make($newPassword)
        ]);
    }


    public function updateAvatar(User $user, $avatarFile): string
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $avatarFile->store('avatars', 'public');

        $this->userRepository->update($user, ['avatar' => $path]);

        return $path;
    }


    public function deleteAvatar(User $user): bool
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);

            return $this->userRepository->update($user, ['avatar' => null]);
        }

        return false;
    }


    public function deleteAccount(User $user, string $password): bool
    {
        if (!Hash::check($password, $user->password)) {
            throw new \Exception('كلمة المرور غير صحيحة');
        }

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        return $this->userRepository->delete($user);
    }
}
