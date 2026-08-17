<?php

// للتذكير: هذا الملف يختبر مشاركة موقع الفني لطلب طوارئ حسب حالته.

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosLocationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeSos(string $status): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'SL-' . uniqid(),
        ]);
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);
        $sos = SosRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'technician_id' => $techUser->id, 'lat' => 33.54, 'lng' => 36.32, 'status' => $status,
        ]);

        return [$techUser, $sos];
    }

    public function test_technician_can_share_location_when_in_progress(): void
    {
        [$techUser, $sos] = $this->makeSos('in_progress');
        Sanctum::actingAs($techUser);

        $this->postJson("/api/sos/{$sos->id}/location", ['lat' => 33.5457764, 'lng' => 36.3250658])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['lat', 'lng', 'timestamp']]);
    }

    public function test_share_location_rejected_when_completed(): void
    {
        [$techUser, $sos] = $this->makeSos('completed');
        Sanctum::actingAs($techUser);

        $this->postJson("/api/sos/{$sos->id}/location", ['lat' => 33.5457764, 'lng' => 36.3250658])
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }
}
