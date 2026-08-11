<?php

// للتذكير: هذا الملف يستبدل اختبار Laravel الافتراضي لـ GET / (غير موجود في هذا المشروع API-only) باختبار حقيقي لـ /api/health.

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'ok',
            ])
            ->assertJsonStructure([
                'success',
                'status',
                'app',
                'database',
                'instance',
                'server_time',
            ]);
    }
}
