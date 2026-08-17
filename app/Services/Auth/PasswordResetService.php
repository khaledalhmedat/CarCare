<?php

namespace App\Services\Auth;

use App\Models\PasswordResetOtp;
use App\Notifications\PasswordResetOtpNotification;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public const OTP_TTL_MINUTES = 10;
    public const RESET_TOKEN_TTL_MINUTES = 15;
    public const MAX_ATTEMPTS = 5;

    public function __construct(protected UserRepositoryInterface $userRepository) {}

    public function requestOtp(string $email): void
    {
        Log::info('Password reset request initiated', ['email' => $email]);

        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            Log::warning('Password reset aborted: User not found', ['email' => $email]);
            return;
        }

        PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordResetOtp::create([
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'attempts_count' => 0,
        ]);

        Log::info('Attempting to dispatch OTP notification', ['email' => $email, 'otp' => $otp]);

        try {
            $user->notify(new PasswordResetOtpNotification($otp, self::OTP_TTL_MINUTES));
            Log::info('OTP notification dispatched successfully', ['email' => $email]);
        } catch (\Throwable $e) {
            Log::warning('Password reset OTP email failed to send', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        if (app()->environment(['local', 'testing'])) {
            Log::info('Password reset OTP (development only)', ['email' => $email, 'otp' => $otp]);
        }
    }

    public function verifyOtp(string $email, string $otp): string
    {
        $record = PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (!$record || $record->isExpired()) {
            $this->fail('otp', 'رمز التحقق غير صالح أو منتهي الصلاحية');
        }

        if ($record->attempts_count >= self::MAX_ATTEMPTS) {
            $this->fail('otp', 'تم تجاوز عدد المحاولات المسموح بها، يرجى طلب رمز جديد');
        }

        if (!Hash::check($otp, $record->otp_hash)) {
            $record->increment('attempts_count');
            $this->fail('otp', 'رمز التحقق غير صالح أو منتهي الصلاحية');
        }

        $resetToken = Str::random(64);

        $record->update([
            'verified_at' => now(),
            'reset_token_hash' => Hash::make($resetToken),
            'reset_token_expires_at' => now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES),
        ]);

        return $resetToken;
    }

    public function resetPassword(string $email, string $resetToken, string $password): void
    {
        $record = PasswordResetOtp::where('email', $email)
            ->whereNotNull('verified_at')
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (
            !$record ||
            $record->isResetTokenExpired() ||
            !$record->reset_token_hash ||
            !Hash::check($resetToken, $record->reset_token_hash)
        ) {
            $this->fail('reset_token', 'رمز إعادة التعيين غير صالح أو منتهي الصلاحية');
        }

        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            $this->fail('reset_token', 'رمز إعادة التعيين غير صالح أو منتهي الصلاحية');
        }

        $this->userRepository->update($user, ['password' => Hash::make($password)]);

        $record->update(['used_at' => now()]);

        $user->tokens()->delete();
    }
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}