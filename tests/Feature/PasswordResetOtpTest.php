<?php

// للتذكير: هذا الملف يختبر تدفق إعادة تعيين كلمة المرور عبر OTP.

namespace Tests\Feature;

use App\Models\PasswordResetOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_forgot_password_returns_generic_success_and_no_otp(): void
    {
        Notification::fake();
        $this->makeUser(['email' => 'known@example.com']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.com']);

        $known->assertOk()->assertJson(['success' => true]);
        $unknown->assertOk()->assertJson(['success' => true]);
        $this->assertEquals($known->json('message'), $unknown->json('message'));
        $this->assertNull($known->json('data.otp'));
        $this->assertNull($known->json('otp'));
    }

    public function test_invalid_otp_is_rejected(): void
    {
        $this->makeUser(['email' => 'otp@example.com']);
        PasswordResetOtp::create([
            'email' => 'otp@example.com',
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts_count' => 0,
        ]);

        $this->postJson('/api/auth/verify-reset-otp', ['email' => 'otp@example.com', 'otp' => '000000'])
            ->assertStatus(422);
    }

    public function test_full_reset_flow_updates_password(): void
    {
        $this->makeUser(['email' => 'reset@example.com', 'password' => Hash::make('OldPass123!')]);
        PasswordResetOtp::create([
            'email' => 'reset@example.com',
            'otp_hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(10),
            'attempts_count' => 0,
        ]);

        $verify = $this->postJson('/api/auth/verify-reset-otp', ['email' => 'reset@example.com', 'otp' => '654321']);
        $verify->assertOk();
        $token = $verify->json('data.reset_token');

        $this->postJson('/api/auth/reset-password', [
            'email' => 'reset@example.com',
            'reset_token' => $token,
            'password' => 'NewPass456!',
            'password_confirmation' => 'NewPass456!',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'reset@example.com', 'password' => 'NewPass456!'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
