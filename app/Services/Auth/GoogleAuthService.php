<?php

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

    /**
     * Authenticate (or provision) a user from a verified Google ID token and
     * return the same token payload shape as normal login.
     *
     * @throws GoogleAuthException
     */
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

        // issues a fresh token and revokes previous ones — identical to normal login
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
            // link an existing password account only if Google has verified the email
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

        // create a new account only from a Google-verified email
        if (!$claims['email_verified']) {
            throw GoogleAuthException::unverifiedEmail();
        }

        // direct property assignment (no mass assignment) — leaves the User model untouched;
        // uuid is set by the model's booted() creating hook.
        $user = new User();
        $user->name = $claims['name'];
        $user->email = $claims['email'];
        $user->password = Hash::make(Str::random(64)); // random, unusable — no password login for social accounts
        $user->status = 'active';
        $user->google_id = $claims['sub'];
        $user->avatar = $claims['avatar'];
        $user->email_verified_at = now();
        $user->save();

        // default customer role only — never a provider role, never bypassing approval
        $role = Role::where('slug', 'user')->first();
        if ($role && !$user->hasRole('user')) {
            $user->roles()->attach($role->id);
        }

        return $user;
    }
}
