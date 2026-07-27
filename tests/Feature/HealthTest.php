<?php

// للتذكير: هذا الملف يختبر نقطة فحص الصحة ووسم النسخة للموازنة.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_returns_ok_with_expected_fields(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJson(['success' => true, 'status' => 'ok'])
            ->assertJsonStructure(['success', 'status', 'app', 'database', 'instance', 'server_time']);
    }

    public function test_health_instance_defaults_safely_when_env_missing(): void
    {
        $response = $this->getJson('/api/health');

        $this->assertNotEmpty($response->json('instance'));
        $this->assertEquals('app', $response->json('instance'));
    }
}
