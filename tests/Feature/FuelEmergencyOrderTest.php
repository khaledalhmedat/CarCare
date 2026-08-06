<?php

// للتذكير: هذا الملف يختبر أن طلب الوقود الطارئ ينجح حتى لو فشل البث.

namespace Tests\Feature;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelEmergencyOrderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'fuel_type' => '95',
            'amount' => 20,
            'delivery_latitude' => 33.54,
            'delivery_longitude' => 36.32,
            'city' => 'دمشق',
        ];
    }

    private int $vehicleId;

    private function actAsCustomerWithVehicle(): void
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FE-' . uniqid(),
        ]);
        $this->vehicleId = $vehicle->id;
        Sanctum::actingAs($customer);
    }

    private function seedApprovedProvider(): void
    {
        $u = $this->makeUser();
        FuelProvider::create([
            'user_id' => $u->id, 'company_name' => 'FuelCo', 'phone' => '05', 'city' => 'دمشق',
            'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'latitude' => 33.54, 'longitude' => 36.32,
        ]);
    }

    public function test_emergency_fuel_order_succeeds(): void
    {
        $this->actAsCustomerWithVehicle();

        $this->postJson('/api/customer/fuel_orders/emergency', $this->payload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, FuelOrder::count());
    }

    public function test_emergency_fuel_order_succeeds_even_if_broadcast_fails(): void
    {
        $this->actAsCustomerWithVehicle();
        $this->seedApprovedProvider();

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('jobs table missing'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        $this->postJson('/api/customer/fuel_orders/emergency', $this->payload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, FuelOrder::count());
    }
}
