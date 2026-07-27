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
}
