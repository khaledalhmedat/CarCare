<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_unauthenticated_user_cannot_register_device(): void
    {
        $this->postJson('/api/devices/register', [
            'fcm_token' => 'token-' . Str::random(20),
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_register_device(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/devices/register', [
            'fcm_token' => 'token-' . Str::random(20),
            'platform' => 'android',
            'device_id' => 'device-1',
        ])->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('user_device_registrations', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'platform' => 'android',
            'device_id' => 'device-1',
            'is_active' => 1,
        ]);
    }

    public function test_register_response_does_not_expose_raw_fcm_token(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/devices/register', [
            'fcm_token' => 'super-secret-token',
        ])->assertOk();

        $response->assertJsonMissingPath('data.fcm_token');
        $this->assertStringNotContainsString('super-secret-token', $response->getContent());
    }

    public function test_registering_same_token_twice_does_not_create_duplicate_rows(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $token = 'token-' . Str::random(20);

        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();
        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        $this->assertEquals(1, \App\Models\UserDeviceRegistration::where('fcm_token', $token)->count());
    }

    public function test_same_device_id_with_new_token_updates_existing_installation(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $oldToken = 'token-' . Str::random(20);
        $newToken = 'token-' . Str::random(20);

        $this->postJson('/api/devices/register', [
            'fcm_token' => $oldToken,
            'device_id' => 'device-1',
        ])->assertOk();

        $this->postJson('/api/devices/register', [
            'fcm_token' => $newToken,
            'device_id' => 'device-1',
        ])->assertOk();

        $this->assertEquals(1, \App\Models\UserDeviceRegistration::where('user_id', $user->id)->where('device_id', 'device-1')->count());
        $this->assertDatabaseHas('user_device_registrations', ['fcm_token' => $newToken, 'device_id' => 'device-1']);
        $this->assertDatabaseMissing('user_device_registrations', ['fcm_token' => $oldToken]);
    }

    public function test_existing_token_from_another_user_is_reassigned_safely(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $token = 'token-' . Str::random(20);

        Sanctum::actingAs($userA);
        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        Sanctum::actingAs($userB);
        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        $this->assertEquals(1, \App\Models\UserDeviceRegistration::where('fcm_token', $token)->count());
        $this->assertDatabaseHas('user_device_registrations', ['fcm_token' => $token, 'user_id' => $userB->id]);
    }

    public function test_user_can_have_multiple_device_registrations(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/devices/register', [
            'fcm_token' => 'token-' . Str::random(20),
            'device_id' => 'device-1',
        ])->assertOk();

        $this->postJson('/api/devices/register', [
            'fcm_token' => 'token-' . Str::random(20),
            'device_id' => 'device-2',
        ])->assertOk();

        $this->assertEquals(2, \App\Models\UserDeviceRegistration::where('user_id', $user->id)->count());
    }

    public function test_authenticated_user_can_unregister_own_token(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $token = 'token-' . Str::random(20);

        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        $this->deleteJson('/api/devices/unregister', ['fcm_token' => $token])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('user_device_registrations', ['fcm_token' => $token]);
    }

    public function test_user_cannot_unregister_another_users_token(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $token = 'token-' . Str::random(20);

        Sanctum::actingAs($userA);
        $this->postJson('/api/devices/register', ['fcm_token' => $token])->assertOk();

        Sanctum::actingAs($userB);
        $this->deleteJson('/api/devices/unregister', ['fcm_token' => $token])->assertOk();

        $this->assertDatabaseHas('user_device_registrations', ['fcm_token' => $token, 'user_id' => $userA->id]);
    }

    public function test_unregistering_one_token_does_not_delete_other_devices(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $tokenA = 'token-' . Str::random(20);
        $tokenB = 'token-' . Str::random(20);

        $this->postJson('/api/devices/register', ['fcm_token' => $tokenA, 'device_id' => 'device-1'])->assertOk();
        $this->postJson('/api/devices/register', ['fcm_token' => $tokenB, 'device_id' => 'device-2'])->assertOk();

        $this->deleteJson('/api/devices/unregister', ['fcm_token' => $tokenA])->assertOk();

        $this->assertDatabaseMissing('user_device_registrations', ['fcm_token' => $tokenA]);
        $this->assertDatabaseHas('user_device_registrations', ['fcm_token' => $tokenB]);
    }

    public function test_invalid_platform_fails_validation(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/devices/register', [
            'fcm_token' => 'token-' . Str::random(20),
            'platform' => 'windows',
        ])->assertStatus(422);
    }

    public function test_missing_fcm_token_fails_validation(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/devices/register', [
            'platform' => 'android',
        ])->assertStatus(422);
    }
}
