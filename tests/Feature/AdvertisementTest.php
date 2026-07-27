<?php

// للتذكير: هذا الملف يختبر إعلانات الأدمن والإعلانات العامة النشطة.

namespace Tests\Feature;

use App\Models\Advertisement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class AdvertisementTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_public_active_ads_returns_ok(): void
    {
        Advertisement::create([
            'title' => 'Active Ad',
            'image_path' => 'advertisements/a.png',
            'placement' => 'home',
            'is_active' => true,
        ]);

        $this->getJson('/api/advertisements/active')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_admin_ads_index_requires_admin(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/admin/advertisements')->assertForbidden();
    }

    public function test_admin_ads_index_requires_authentication(): void
    {
        $this->getJson('/api/admin/advertisements')->assertUnauthorized();
    }

    public function test_admin_can_list_ads(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $this->getJson('/api/admin/advertisements')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data', 'meta']);
    }
}
