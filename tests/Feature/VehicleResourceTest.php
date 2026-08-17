<?php

// للتذكير: هذا الملف يختبر أن VehicleResource لا ينهار عند حذف مالك المركبة حذفاً ناعماً.

namespace Tests\Feature;

use App\Http\Resources\VehicleResource;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class VehicleResourceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeVehicle($owner): Vehicle
    {
        return Vehicle::create([
            'user_id' => $owner->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'VR-' . uniqid(),
        ]);
    }

    public function test_owner_is_returned_for_a_normal_vehicle(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);

        $data = (new VehicleResource($vehicle->fresh()))->toArray(request());

        $this->assertEquals([
            'id' => $owner->id,
            'name' => $owner->name,
        ], $data['owner']);
    }

    public function test_owner_is_null_when_user_is_soft_deleted(): void
    {
        $owner = $this->makeUser();
        $vehicle = $this->makeVehicle($owner);
        $owner->delete();

        $fresh = $vehicle->fresh();
        $this->assertNull($fresh->user);

        $data = (new VehicleResource($fresh))->toArray(request());

        $this->assertNull($data['owner']);
        $this->assertEquals($vehicle->id, $data['id']);
        $this->assertEquals('Toyota', $data['brand']);
        $this->assertEquals('Corolla', $data['model']);
        $this->assertEquals($vehicle->plate_number, $data['plate_number']);
    }
}
