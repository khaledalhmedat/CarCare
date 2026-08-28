<?php

// للتذكير: هذا الملف يختبر قاعدة نطاق الخدمة (30 كم) ودعم نوع الوقود عند قبول مزود الوقود لطلب.

namespace Tests\Feature;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelOrderAcceptServiceRangeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    // إحداثيات قريبة من دمشق تقريبًا (~2.25 كم فرق) لسيناريو "ضمن النطاق".
    private const NEAR_PROVIDER_LAT = 33.5460;
    private const NEAR_PROVIDER_LNG = 36.3249;
    private const NEAR_DELIVERY_LAT = 33.5300;
    private const NEAR_DELIVERY_LNG = 36.3100;

    // دمشق مقابل درعا تقريبًا (~105 كم) لسيناريو "خارج النطاق".
    private const FAR_DELIVERY_LAT = 32.6189;
    private const FAR_DELIVERY_LNG = 36.1021;

    private function makeProvider(?float $lat = self::NEAR_PROVIDER_LAT, ?float $lng = self::NEAR_PROVIDER_LNG, array $fuelTypes = ['95', '98', 'diesel']): User
    {
        $providerUser = $this->makeUserWithRole('fuel-provider');
        FuelProvider::create([
            'user_id' => $providerUser->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'latitude' => $lat, 'longitude' => $lng, 'fuel_types' => $fuelTypes,
        ]);

        return $providerUser;
    }

    private function makePendingOrder(?float $lat, ?float $lng, string $fuelType = '95'): FuelOrder
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FR-' . uniqid(),
        ]);

        return FuelOrder::create(array_merge([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'fuel_type' => $fuelType, 'amount' => 20, 'delivery_address' => 'دمشق',
            'delivery_latitude' => $lat, 'delivery_longitude' => $lng,
            'status' => 'pending',
        ], $this->eligibleRadiusState()));
    }

    public function test_provider_within_30km_and_supported_fuel_type_can_accept(): void
    {
        $provider = $this->makeProvider();
        $order = $this->makePendingOrder(self::NEAR_DELIVERY_LAT, self::NEAR_DELIVERY_LNG, '95');
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $order->fresh()->status);
    }

    public function test_provider_beyond_30km_is_rejected_with_out_of_service_range(): void
    {
        $provider = $this->makeProvider();
        $order = $this->makePendingOrder(self::FAR_DELIVERY_LAT, self::FAR_DELIVERY_LNG, '95');
        Sanctum::actingAs($provider);

        $response = $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'OUT_OF_SERVICE_RANGE']);

        $response->assertJsonPath('data.max_distance_km', 70);
        $this->assertGreaterThan(70, $response->json('data.distance_km'));

        $fresh = $order->fresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->fuel_provider_id);
    }

    public function test_unsupported_fuel_type_is_rejected_even_when_within_range(): void
    {
        // المزود يوفر 98 و diesel فقط، بينما الطلب من نوع 95، مع أن المسافة ضمن النطاق
        $provider = $this->makeProvider(fuelTypes: ['98', 'diesel']);
        $order = $this->makePendingOrder(self::NEAR_DELIVERY_LAT, self::NEAR_DELIVERY_LNG, '95');
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'UNSUPPORTED_FUEL_TYPE']);

        $fresh = $order->fresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->fuel_provider_id);
    }

    public function test_missing_provider_coordinates_is_rejected_with_provider_location_required(): void
    {
        $provider = $this->makeProvider(null, null);
        $order = $this->makePendingOrder(self::NEAR_DELIVERY_LAT, self::NEAR_DELIVERY_LNG, '95');
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'PROVIDER_LOCATION_REQUIRED']);

        $this->assertEquals('pending', $order->fresh()->status);
    }

    public function test_missing_delivery_coordinates_is_rejected_with_delivery_location_required(): void
    {
        $provider = $this->makeProvider();
        $order = $this->makePendingOrder(null, null, '95');
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'DELIVERY_LOCATION_REQUIRED']);

        $this->assertEquals('pending', $order->fresh()->status);
    }
}
