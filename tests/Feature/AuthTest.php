<?php

// للتذكير: هذا الملف يختبر تسجيل الدخول والتسجيل وحماية المسارات.

namespace Tests\Feature;

use App\Models\UserDeviceRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_register_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'message', 'data' => ['user', 'token', 'token_type']]);
    }

    public function test_login_returns_token(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Login User',
            'email' => 'login@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_current_user_when_authenticated(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/auth/me')->assertOk()->assertJson(['success' => true]);
    }

    public function test_protected_route_rejects_unauthenticated(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_logout_with_fcm_token_deactivates_that_device_registration(): void
    {
        $userA = $this->makeUser();
        Sanctum::actingAs($userA);
        $token = 'token-' . Str::random(20);

        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        $this->postJson('/api/auth/logout', ['fcm_token' => $token])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('user_device_registrations', [
            'fcm_token' => $token,
            'user_id' => $userA->id,
        ]);

        $userB = $this->makeUser();
        Sanctum::actingAs($userB);
        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        $this->assertEquals(1, UserDeviceRegistration::where('fcm_token', $token)->count());
        $this->assertDatabaseHas('user_device_registrations', [
            'fcm_token' => $token,
            'user_id' => $userB->id,
            'is_active' => 1,
        ]);
    }

    public function test_logout_without_fcm_token_still_succeeds(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_logout_of_one_device_does_not_unregister_another_device(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $tokenA = 'token-' . Str::random(20);
        $tokenB = 'token-' . Str::random(20);

        $this->postJson('/api/devices/register', ['fcm_token' => $tokenA, 'device_id' => 'device-1'])->assertOk();
        $this->postJson('/api/devices/register', ['fcm_token' => $tokenB, 'device_id' => 'device-2'])->assertOk();

        $this->postJson('/api/auth/logout', ['fcm_token' => $tokenA])->assertOk();

        $this->assertDatabaseMissing('user_device_registrations', ['fcm_token' => $tokenA]);
        $this->assertDatabaseHas('user_device_registrations', ['fcm_token' => $tokenB, 'user_id' => $user->id]);
    }
}
