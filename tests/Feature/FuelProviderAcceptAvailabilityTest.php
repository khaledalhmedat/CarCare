<?php

// للتذكير: هذا الملف يختبر أن مزود الوقود غير المتاح لا يمكنه قبول طلب مباشرة عبر معرف الطلب.

namespace Tests\Feature;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelProviderAcceptAvailabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private const NEAR_LAT = 33.5460;
    private const NEAR_LNG = 36.3249;

    private function makeProvider(bool $isAvailable): User
    {
        $providerUser = $this->makeUserWithRole('fuel-provider');
        FuelProvider::create([
            'user_id' => $providerUser->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => $isAvailable,
            'latitude' => self::NEAR_LAT, 'longitude' => self::NEAR_LNG, 'fuel_types' => ['95', '98', 'diesel'],
        ]);

        return $providerUser;
    }

    private function makePendingOrder(): FuelOrder
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FA-' . uniqid(),
        ]);

        return FuelOrder::create(array_merge([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'fuel_type' => '95', 'amount' => 20, 'delivery_address' => 'دمشق',
            'delivery_latitude' => self::NEAR_LAT, 'delivery_longitude' => self::NEAR_LNG,
            'status' => 'pending',
        ], $this->eligibleRadiusState()));
    }

    public function test_available_approved_provider_can_accept_pending_order(): void
    {
        $provider = $this->makeProvider(true);
        $order = $this->makePendingOrder();
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $order->fresh()->status);
    }

    public function test_unavailable_provider_cannot_accept_pending_order(): void
    {
        $provider = $this->makeProvider(false);
        $order = $this->makePendingOrder();
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $fresh = $order->fresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->fuel_provider_id);
    }

    public function test_two_available_providers_racing_still_first_accept_wins(): void
    {
        $providerA = $this->makeProvider(true);
        $providerB = $this->makeProvider(true);
        $order = $this->makePendingOrder();

        Sanctum::actingAs($providerA);
        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])->assertOk();

        Sanctum::actingAs($providerB);
        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(
            FuelProvider::where('user_id', $providerA->id)->first()->id,
            $order->fresh()->fuel_provider_id
        );
    }
}
