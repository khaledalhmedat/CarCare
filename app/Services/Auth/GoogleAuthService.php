<?php

// للتذكير: هذا الملف مسؤول عن تسجيل الدخول عبر جوجل: ربط أو إنشاء المستخدم وإصدار التوكن.

namespace App\Services\Auth;

use App\Exceptions\GoogleAuthException;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GoogleAuthService
{
    public function __construct(
        protected GoogleTokenVerifier $verifier,
        protected UserRepositoryInterface $userRepository,
        protected AuthRepositoryInterface $authRepository
    ) {}

    public function loginWithIdToken(string $idToken): array
    {
        $claims = $this->verifier->verify($idToken);

        $user = User::where('google_id', $claims['sub'])->first();

        if (!$user) {
            $user = $this->linkOrCreate($claims);
        }

        if ($user->status !== 'active') {
            throw GoogleAuthException::inactiveAccount();
        }

        $token = $this->authRepository->createToken($user);

        return [
            'user' => new UserResource($user->load(['tenant', 'roles'])),
            'token' => $token,
            'message' => 'تم تسجيل الدخول عبر جوجل بنجاح',
        ];
    }

    protected function linkOrCreate(array $claims): User
    {
        $existing = $this->userRepository->findByEmail($claims['email']);

        if ($existing) {
            if (!$claims['email_verified']) {
                throw GoogleAuthException::unverifiedEmail();
            }

            $existing->google_id = $claims['sub'];
            if (empty($existing->avatar) && $claims['avatar']) {
                $existing->avatar = $claims['avatar'];
            }
            if (empty($existing->email_verified_at)) {
                $existing->email_verified_at = now();
            }
            $existing->save();

            return $existing;
        }

        if (!$claims['email_verified']) {
            throw GoogleAuthException::unverifiedEmail();
        }

        $user = new User();
        $user->name = $claims['name'];
        $user->email = $claims['email'];
        $user->password = Hash::make(Str::random(64));
        $user->status = 'active';
        $user->google_id = $claims['sub'];
        $user->avatar = $claims['avatar'];
        $user->email_verified_at = now();
        $user->save();

        $role = Role::where('slug', 'user')->first();
        if ($role && !$user->hasRole('user')) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }
}
