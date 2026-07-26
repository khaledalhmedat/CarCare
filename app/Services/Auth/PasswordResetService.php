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

    /**
     * Generate + store (hashed) a fresh OTP and email it. Never reveals whether the
     * email exists, never returns the OTP, never fails just because mail is down.
     */
    public function requestOtp(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);

        // silently no-op for unknown emails — caller always returns a generic message
        if (!$user) {
            return;
        }

        // invalidate any previous unused OTPs for this email
        PasswordResetOtp::where('email', $email)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        // secure 6-digit numeric OTP (may legitimately start with 0)
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordResetOtp::create([
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'attempts_count' => 0,
        ]);

        // attempt delivery; a mail failure must not break the endpoint
        try {
            $user->notify(new PasswordResetOtpNotification($otp, self::OTP_TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('Password reset OTP email failed to send', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        // local/testing convenience only — never logged in production
        if (app()->environment(['local', 'testing'])) {
            Log::info('Password reset OTP (development only)', ['email' => $email, 'otp' => $otp]);
        }
    }

    /**
     * Verify the OTP and, on success, issue a short-lived reset token (hashed at rest).
     * Returns the plaintext reset token to hand back to the client.
     */
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

    /**
     * Consume a verified reset token, set the new password, and revoke all sessions.
     */
    public function resetPassword(string $email, string $resetToken, string $password): void
    {
        $record = PasswordResetOtp::where('email', $email)
            ->whereNotNull('verified_at')
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if (
            !$record
            || $record->isResetTokenExpired()
            || !$record->reset_token_hash
            || !Hash::check($resetToken, $record->reset_token_hash)
        ) {
            $this->fail('reset_token', 'رمز إعادة التعيين غير صالح أو منتهي الصلاحية');
        }

        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            $this->fail('reset_token', 'رمز إعادة التعيين غير صالح أو منتهي الصلاحية');
        }

        $this->userRepository->update($user, ['password' => Hash::make($password)]);

        // consume the record so it cannot be reused
        $record->update(['used_at' => now()]);

        // revoke all existing Sanctum tokens — force re-login everywhere
        $user->tokens()->delete();
    }

    /**
     * @throws ValidationException  renders as the standard 422 via the exception handler
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
