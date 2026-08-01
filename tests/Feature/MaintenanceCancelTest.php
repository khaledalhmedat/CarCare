<?php

// للتذكير: هذا الملف يختبر إلغاء طلب الصيانة مع سبب الإلغاء.

namespace Tests\Feature;

use App\Models\MaintenanceRequest;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class MaintenanceCancelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_customer_can_cancel_maintenance_request_with_reason(): void
    {
        $user = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'MC-' . uniqid(),
        ]);
        $request = MaintenanceRequest::create([
            'user_id' => $user->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'لم أعد بحاجة للخدمة',
        ])->assertOk()->assertJson(['success' => true]);

        $fresh = $request->fresh();
        $this->assertEquals('cancelled', $fresh->status);
        $this->assertNotNull($fresh->cancellation_reason);
        $this->assertNotNull($fresh->cancelled_at);
    }
}
