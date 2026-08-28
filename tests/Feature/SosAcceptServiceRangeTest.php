<?php

// للتذكير: هذا الملف يختبر قاعدة نطاق الخدمة (30 كم) عند قبول الفني لطلب SOS.

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosAcceptServiceRangeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    // إحداثيات قريبة من دمشق تقريبًا (~2.25 كم فرق) لسيناريو "ضمن النطاق".
    private const NEAR_TECH_LAT = 33.5460;
    private const NEAR_TECH_LNG = 36.3249;
    private const NEAR_SOS_LAT = 33.5300;
    private const NEAR_SOS_LNG = 36.3100;

    // دمشق مقابل درعا تقريبًا (~105 كم) لسيناريو "خارج النطاق".
    private const FAR_SOS_LAT = 32.6189;
    private const FAR_SOS_LNG = 36.1021;

    private function makeTechnician(?float $lat = self::NEAR_TECH_LAT, ?float $lng = self::NEAR_TECH_LNG): User
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
            'latitude' => $lat, 'longitude' => $lng,
        ]);

        return $techUser;
    }

    private function makeOpenSos(float $lat, float $lng): SosRequest
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'SR-' . uniqid(),
        ]);

        return SosRequest::create(array_merge([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'lat' => $lat, 'lng' => $lng, 'status' => 'open',
        ], $this->eligibleRadiusState()));
    }

    public function test_technician_within_30km_can_accept(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeOpenSos(self::NEAR_SOS_LAT, self::NEAR_SOS_LNG);
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $sos->fresh()->status);
        $this->assertEquals($tech->id, $sos->fresh()->technician_id);
    }

    public function test_technician_beyond_30km_is_rejected_with_out_of_service_range(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeOpenSos(self::FAR_SOS_LAT, self::FAR_SOS_LNG);
        Sanctum::actingAs($tech);

        $response = $this->postJson("/api/technician/sos/requests/{$sos->id}/accept", [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'OUT_OF_SERVICE_RANGE']);

        $response->assertJsonPath('data.max_distance_km', 70);
        $this->assertGreaterThan(70, $response->json('data.distance_km'));
        $this->assertIsString($response->json('message'));
        $this->assertNotEmpty($response->json('message'));

        // الطلب يبقى open ولم يُسند لأي فني
        $fresh = $sos->fresh();
        $this->assertEquals('open', $fresh->status);
        $this->assertNull($fresh->technician_id);
    }

    public function test_technician_without_coordinates_is_rejected_with_provider_location_required(): void
    {
        $tech = $this->makeTechnician(null, null);
        $sos = $this->makeOpenSos(self::NEAR_SOS_LAT, self::NEAR_SOS_LNG);
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/accept", [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'PROVIDER_LOCATION_REQUIRED']);

        $this->assertEquals('open', $sos->fresh()->status);
    }
}
