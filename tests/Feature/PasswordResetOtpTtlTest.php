<?php

// للتذكير: هذا الملف يختبر مدة صلاحية Forgot Password OTP (5 دقائق) عبر التدفق الفعلي للخدمة.

namespace Tests\Feature;

use App\Models\PasswordResetOtp;
use App\Services\Auth\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class PasswordResetOtpTtlTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_otp_is_valid_before_5_minutes_elapse(): void
    {
        Notification::fake();
        $email = 'ttl-valid@example.com';
        $this->makeUser(['email' => $email]);

        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();

        $record = PasswordResetOtp::where('email', $email)->latest('id')->first();
        $this->assertEquals(
            PasswordResetService::OTP_TTL_MINUTES,
            5,
            'OTP TTL constant must be 5 minutes'
        );
        $this->assertFalse($record->isExpired());

        $this->travel(4)->minutes();
        $this->assertFalse($record->fresh()->isExpired());
    }

    public function test_otp_is_expired_after_5_minutes_elapse(): void
    {
        $email = 'ttl-expired@example.com';
        $this->makeUser(['email' => $email]);

        $record = PasswordResetOtp::create([
            'email' => $email,
            'otp_hash' => bcrypt('112233'),
            'expires_at' => now()->addMinutes(PasswordResetService::OTP_TTL_MINUTES),
            'attempts_count' => 0,
        ]);

        // 5 minutes + 1 second, comfortably past the TTL boundary
        $this->travel(5 * 60 + 1)->seconds();

        $this->assertTrue($record->fresh()->isExpired());

        $this->postJson('/api/auth/verify-reset-otp', ['email' => $email, 'otp' => '112233'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');
    }

    public function test_resend_invalidates_previous_otp(): void
    {
        Notification::fake();
        $email = 'ttl-resend@example.com';
        $this->makeUser(['email' => $email]);

        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();
        $first = PasswordResetOtp::where('email', $email)->latest('id')->first();

        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();
        $second = PasswordResetOtp::where('email', $email)->latest('id')->first();

        $this->assertNotEquals($first->id, $second->id);
        $this->assertNotNull($first->fresh()->used_at);
        $this->assertNull($second->fresh()->used_at);
    }

    public function test_new_otp_after_resend_is_valid_for_5_minutes(): void
    {
        Notification::fake();
        $email = 'ttl-resend-valid@example.com';
        $this->makeUser(['email' => $email]);

        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();

        $latest = PasswordResetOtp::where('email', $email)->whereNull('used_at')->latest('id')->first();

        $expectedTtlSeconds = PasswordResetService::OTP_TTL_MINUTES * 60;
        $actualTtlSeconds = $latest->expires_at->timestamp - now()->timestamp;

        $this->assertEqualsWithDelta($expectedTtlSeconds, $actualTtlSeconds, 2);
        $this->assertFalse($latest->isExpired());
    }

    public function test_reset_token_ttl_remains_15_minutes(): void
    {
        $email = 'ttl-reset-token@example.com';
        $this->makeUser(['email' => $email]);

        PasswordResetOtp::create([
            'email' => $email,
            'otp_hash' => bcrypt('998877'),
            'expires_at' => now()->addMinutes(PasswordResetService::OTP_TTL_MINUTES),
            'attempts_count' => 0,
        ]);

        $response = $this->postJson('/api/auth/verify-reset-otp', ['email' => $email, 'otp' => '998877'])
            ->assertOk();

        $this->assertEquals(15, PasswordResetService::RESET_TOKEN_TTL_MINUTES);
        $this->assertEquals(15, $response->json('data.expires_in_minutes'));
    }
}
