<?php

// للتذكير: هذا الملف يختبر صلاحيات وواجهات لوحة تحكم الأدمن.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_non_admin_cannot_access_dashboard(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/admin/dashboard/summary')->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_dashboard(): void
    {
        $this->getJson('/api/admin/dashboard/summary')->assertUnauthorized();
    }

    public function test_admin_can_access_summary(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/dashboard/summary')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['users', 'providers', 'operations']]);
    }

    public function test_admin_can_access_operations(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/dashboard/operations?period=month&group_by=month')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_admin_can_access_revenue(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/dashboard/revenue')
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
