<?php

// للتذكير: هذا الملف يختبر إلغاء الفني لطلب الطوارئ حسب الحالة والصلاحية.

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosCancelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeTechnician()
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);

        return $techUser;
    }

    private function makeSos($techUser, string $status): SosRequest
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'SC-' . uniqid(),
        ]);

        return SosRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'technician_id' => $techUser->id, 'lat' => 33.54, 'lng' => 36.32, 'status' => $status,
        ]);
    }

    public function test_technician_can_cancel_accepted_request(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'accepted');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('open', $sos->fresh()->status);
    }

    public function test_technician_can_cancel_in_progress_request(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'in_progress');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_technician_cannot_cancel_completed_request(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'completed');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_wrong_technician_cannot_cancel_others_request(): void
    {
        $owner = $this->makeTechnician();
        $sos = $this->makeSos($owner, 'accepted');
        $other = $this->makeTechnician();
        Sanctum::actingAs($other);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals('accepted', $sos->fresh()->status);
    }
}
