<?php

// للتذكير: هذا الملف يختبر صلاحيات وواجهات الفوترة والفواتير للأدمن.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_non_admin_cannot_access_billing_settings(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/admin/billing/settings')->assertForbidden();
    }

    public function test_unauthenticated_cannot_access_billing_settings(): void
    {
        $this->getJson('/api/admin/billing/settings')->assertUnauthorized();
    }

    public function test_admin_can_list_billing_settings(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/billing/settings')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_admin_can_list_invoices(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/billing/invoices')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_admin_can_list_provider_status(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/billing/provider-status')
            ->assertOk()
            ->assertJson(['success' => true]);
    }
}
