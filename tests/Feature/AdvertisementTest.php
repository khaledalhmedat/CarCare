<?php

// للتذكير: هذا الملف يختبر إعلانات الأدمن والإعلانات العامة النشطة.

namespace Tests\Feature;

use App\Models\Advertisement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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

        $response = $this->getJson('/api/advertisements/active')
            ->assertOk()
            ->assertJson(['success' => true]);

        $imageUrl = $response->json('data.0.image_url');
        $this->assertStringContainsString('/storage/advertisements/a.png', $imageUrl);
        $this->assertStringStartsWith('http://', $imageUrl);
    }

    public function test_active_ad_image_url_is_https_when_scheme_is_forced_like_production(): void
    {
        Advertisement::create([
            'title' => 'Active Ad',
            'image_path' => 'advertisements/b.png',
            'placement' => 'home',
            'is_active' => true,
        ]);

        // يحاكي ما يقوم به AppServiceProvider::boot() في بيئة الإنتاج فقط
        URL::forceScheme('https');

        $imageUrl = $this->getJson('/api/advertisements/active')
            ->assertOk()
            ->json('data.0.image_url');

        $this->assertStringStartsWith('https://', $imageUrl);
        $this->assertStringContainsString('/storage/advertisements/b.png', $imageUrl);
    }

    public function test_image_url_accessor_is_null_when_image_path_missing(): void
    {
        // image_path عمود إلزامي في قاعدة البيانات، لذا يُختبر السلوك الآمن على النموذج مباشرة دون حفظ صف فعلي
        $ad = new Advertisement(['image_path' => null]);

        $this->assertNull($ad->image_url);
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
