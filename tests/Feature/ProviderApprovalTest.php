<?php

// للتذكير: هذا الملف يختبر صلاحيات وواجهات اعتماد مزودي الخدمة للأدمن.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ProviderApprovalTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_non_admin_cannot_list_provider_approvals(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/admin/provider-approvals/technician')->assertForbidden();
    }

    public function test_unauthenticated_cannot_list_provider_approvals(): void
    {
        $this->getJson('/api/admin/provider-approvals/technician')->assertUnauthorized();
    }

    public function test_admin_can_list_pending_technicians(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/provider-approvals/technician?status=pending')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_invalid_provider_type_is_rejected(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/provider-approvals/not-a-type')->assertStatus(422);
    }
}
