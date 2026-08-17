<?php

// للتذكير: هذا الملف يختبر endpoint سجل الوقود الموجود مسبقاً (GET /vehicles/{id}/fuel-logs) وملكية المركبة.

namespace Tests\Feature;

use App\Models\FuelLog;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class VehicleFuelHistoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeVehicle(User $owner): Vehicle
    {
        return Vehicle::create([
            'user_id' => $owner->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FH-' . uniqid(),
        ]);
    }

    private function makeFuelLog(Vehicle $vehicle, array $overrides = []): FuelLog
    {
        return FuelLog::create(array_merge([
            'vehicle_id' => $vehicle->id, 'amount' => 20, 'fuel_type' => '95', 'cost' => 50, 'km_at_fill' => 1000,
        ], $overrides));
    }

    public function test_owner_can_view_own_fuel_history(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);
        $this->makeFuelLog($vehicle);
        Sanctum::actingAs($owner);

        $this->getJson("/api/vehicles/{$vehicle->id}/fuel-logs")
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data.data');
    }

    public function test_other_user_cannot_view_vehicle_fuel_history(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);
        $this->makeFuelLog($vehicle);

        $other = $this->makeUser();
        Sanctum::actingAs($other);

        $this->getJson("/api/vehicles/{$vehicle->id}/fuel-logs")
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_fuel_history_orders_newest_first(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);

        // created_at ليس ضمن fillable على FuelLog، لذا يُضبط بعد الإنشاء عبر forceFill لتفادي إسقاطه بصمت
        $older = $this->makeFuelLog($vehicle);
        $older->forceFill(['created_at' => now()->subDays(3)])->save();

        $newer = $this->makeFuelLog($vehicle);
        $newer->forceFill(['created_at' => now()])->save();

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/vehicles/{$vehicle->id}/fuel-logs")->assertOk();

        $ids = array_column($response->json('data.data'), 'id');
        $this->assertEquals([$newer->id, $older->id], $ids);
    }

    public function test_empty_fuel_history_returns_empty_list(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);
        Sanctum::actingAs($owner);

        $this->getJson("/api/vehicles/{$vehicle->id}/fuel-logs")
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(0, 'data.data');
    }

    public function test_fuel_history_does_not_leak_other_vehicle_logs(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);
        $this->makeFuelLog($vehicle);

        $otherOwner = $this->makeUser();
        $otherVehicle = $this->makeVehicle($otherOwner);
        $this->makeFuelLog($otherVehicle);
        $this->makeFuelLog($otherVehicle);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/vehicles/{$vehicle->id}/fuel-logs")->assertOk();

        $this->assertCount(1, $response->json('data.data'));
        foreach ($response->json('data.data') as $log) {
            $this->assertEquals($vehicle->id, $log['vehicle_id']);
        }
    }
}
