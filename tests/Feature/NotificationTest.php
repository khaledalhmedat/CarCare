<?php

// للتذكير: هذا الملف يختبر واجهات إشعارات المستخدم الحالي.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function seedNotification($user, bool $read = false): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'provider_approved',
            'data' => ['type' => 'provider_approved', 'title' => 'ok', 'body' => 'ok', 'data' => []],
            'read_at' => $read ? now() : null,
        ]);
    }

    private function seedNotificationFor($user, array $overrides = [])
    {
        return $user->notifications()->create(array_merge([
            'id' => (string) Str::uuid(),
            'type' => 'provider_approved',
            'data' => ['type' => 'provider_approved', 'title' => 'ok', 'body' => 'ok', 'data' => []],
            'read_at' => null,
        ], $overrides));
    }

    public function test_unauthenticated_cannot_list_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_user_can_list_notifications(): void
    {
        $user = $this->makeUser();
        $this->seedNotification($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data', 'meta' => ['total', 'unread_count']]);
    }

    public function test_unread_count_shape(): void
    {
        $user = $this->makeUser();
        $this->seedNotification($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => ['unread_count']])
            ->assertJson(['data' => ['unread_count' => 1]]);
    }

    public function test_mark_all_as_read_clears_unread(): void
    {
        $user = $this->makeUser();
        $this->seedNotification($user);
        $this->seedNotification($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/notifications/mark-all-as-read')->assertOk();

        $this->getJson('/api/notifications/unread-count')->assertJson(['data' => ['unread_count' => 0]]);
    }

    public function test_owner_can_view_notification_details(): void
    {
        $user = $this->makeUser();
        $notification = $this->seedNotificationFor($user, [
            'type' => 'provider_approved',
            'data' => ['type' => 'provider_approved', 'title' => 'ok', 'body' => 'ok', 'data' => ['foo' => 'bar']],
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['id', 'type', 'title', 'body', 'data', 'read_at', 'created_at']])
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.type', 'provider_approved')
            ->assertJsonPath('data.title', 'ok')
            ->assertJsonPath('data.body', 'ok')
            ->assertJsonPath('data.data', ['foo' => 'bar'])
            ->assertJsonPath('data.read_at', null);
    }

    public function test_mark_one_notification_as_read(): void
    {
        $user = $this->makeUser();
        $target = $this->seedNotificationFor($user);
        $other = $this->seedNotificationFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/notifications/{$target->id}/mark-as-read")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($target->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_delete_owned_notification(): void
    {
        $user = $this->makeUser();
        $target = $this->seedNotificationFor($user);
        $other = $this->seedNotificationFor($user);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/notifications/{$target->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('notifications', ['id' => $target->id]);
        $this->assertDatabaseHas('notifications', ['id' => $other->id]);
    }

    public function test_show_enforces_ownership_isolation(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $bNotification = $this->seedNotificationFor($userB);
        Sanctum::actingAs($userA);

        $this->getJson("/api/notifications/{$bNotification->id}")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'الإشعار غير موجود']);

        $this->assertDatabaseHas('notifications', ['id' => $bNotification->id]);
    }

    public function test_mark_as_read_enforces_ownership_isolation(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $bNotification = $this->seedNotificationFor($userB);
        Sanctum::actingAs($userA);

        $this->postJson("/api/notifications/{$bNotification->id}/mark-as-read")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'الإشعار غير موجود']);

        $this->assertNull($bNotification->fresh()->read_at);
    }

    public function test_delete_enforces_ownership_isolation(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $bNotification = $this->seedNotificationFor($userB);
        Sanctum::actingAs($userA);

        $this->deleteJson("/api/notifications/{$bNotification->id}")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'الإشعار غير موجود']);

        $this->assertDatabaseHas('notifications', ['id' => $bNotification->id]);
    }

    public function test_mark_all_as_read_enforces_ownership_isolation(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $aNotification = $this->seedNotificationFor($userA);
        $bNotification = $this->seedNotificationFor($userB);
        Sanctum::actingAs($userA);

        $this->postJson('/api/notifications/mark-all-as-read')->assertOk();

        $this->assertNotNull($aNotification->fresh()->read_at);
        $this->assertNull($bNotification->fresh()->read_at);
    }

    public function test_unread_filter_excludes_read_notifications(): void
    {
        $user = $this->makeUser();
        $read = $this->seedNotificationFor($user, ['read_at' => now()]);
        $unread = $this->seedNotificationFor($user);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?unread=1')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($unread->id));
        $this->assertFalse($ids->contains($read->id));
        $this->assertEquals(1, $response->json('meta.unread_count'));
    }

    public function test_type_filter_returns_only_matching_type(): void
    {
        $user = $this->makeUser();
        $typeA = $this->seedNotificationFor($user, [
            'type' => 'type_a',
            'data' => ['type' => 'type_a', 'title' => 'ok', 'body' => 'ok', 'data' => []],
        ]);
        $typeB = $this->seedNotificationFor($user, [
            'type' => 'type_b',
            'data' => ['type' => 'type_b', 'title' => 'ok', 'body' => 'ok', 'data' => []],
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?type=type_a')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($typeA->id));
        $this->assertFalse($ids->contains($typeB->id));
    }

    public function test_list_is_paginated(): void
    {
        $user = $this->makeUser();
        $this->seedNotificationFor($user);
        $this->seedNotificationFor($user);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notifications?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(2, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.per_page'));
        $this->assertEquals(1, $response->json('meta.current_page'));
        $this->assertEquals(2, $response->json('meta.unread_count'));
    }

    public function test_missing_notification_id_returns_safe_404_for_all_actions(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $missingId = (string) Str::uuid();

        $this->getJson("/api/notifications/{$missingId}")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'الإشعار غير موجود']);

        $this->postJson("/api/notifications/{$missingId}/mark-as-read")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'الإشعار غير موجود']);

        $this->deleteJson("/api/notifications/{$missingId}")
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'الإشعار غير موجود']);
    }
}
