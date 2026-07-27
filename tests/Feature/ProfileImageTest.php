<?php

// للتذكير: هذا الملف يختبر رفع وحذف صورة الملف الشخصي وحقولها في المصادقة.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ProfileImageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function fakePng(): UploadedFile
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $path = sys_get_temp_dir() . '/pi_' . uniqid() . '.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'profile.png', 'image/png', null, true);
    }

    public function test_me_returns_null_image_fields_for_new_user(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonStructure(['data' => ['profile_image_path', 'profile_image_url']])
            ->assertJson(['data' => ['profile_image_path' => null, 'profile_image_url' => null]]);
    }

    public function test_user_can_upload_profile_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser());

        $response = $this->post('/api/auth/profile-image', ['profile_image' => $this->fakePng()], ['Accept' => 'application/json']);

        $response->assertOk()->assertJson(['success' => true]);
        $path = $response->json('data.user.profile_image_path');

        $this->assertNotNull($path);
        $this->assertNotNull($response->json('data.user.profile_image_url'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_me_returns_image_after_upload(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser());

        $this->post('/api/auth/profile-image', ['profile_image' => $this->fakePng()], ['Accept' => 'application/json'])->assertOk();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissing(['data' => ['profile_image_path' => null]]);
        $this->assertNotNull($this->getJson('/api/auth/me')->json('data.profile_image_path'));
    }

    public function test_user_can_delete_profile_image(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser());

        $this->post('/api/auth/profile-image', ['profile_image' => $this->fakePng()], ['Accept' => 'application/json'])->assertOk();

        $this->deleteJson('/api/auth/profile-image')
            ->assertOk()
            ->assertJson(['data' => ['user' => ['profile_image_path' => null, 'profile_image_url' => null]]]);
    }

    public function test_unauthenticated_cannot_upload_or_delete(): void
    {
        $this->postJson('/api/auth/profile-image')->assertUnauthorized();
        $this->deleteJson('/api/auth/profile-image')->assertUnauthorized();
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->makeUser());

        $bad = UploadedFile::fake()->create('doc.pdf', 10);

        $this->post('/api/auth/profile-image', ['profile_image' => $bad], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
