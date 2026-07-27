<?php

// للتذكير: هذا الملف يختبر واجهات تقارير الأدمن وصلاحياتها.

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private array $endpoints = [
        '/api/admin/reports/overview',
        '/api/admin/reports/operations',
        '/api/admin/reports/providers',
        '/api/admin/reports/financial',
        '/api/admin/reports/billing',
        '/api/admin/reports/advertisements',
    ];

    public function test_admin_can_access_all_reports(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        foreach ($this->endpoints as $endpoint) {
            $this->getJson($endpoint)->assertOk()->assertJson(['success' => true]);
        }
    }

    public function test_non_admin_cannot_access_reports(): void
    {
        Sanctum::actingAs($this->makeUser());

        foreach ($this->endpoints as $endpoint) {
            $this->getJson($endpoint)->assertForbidden();
        }
    }
}
