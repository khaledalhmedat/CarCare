<?php

// للتذكير: هذا الملف يختبر قواعد التحقق عند إنشاء وتعديل المركبة (تحديث جزئي، رقم لوحة، ماركة/طراز).

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class VehicleValidationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function actAsCustomer(): User
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'plate_number' => 'ABC 1234',
        ], $overrides);
    }

    // ===================== Create =====================

    public function test_normal_vehicle_creation_succeeds(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload())
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, Vehicle::count());
    }

    public function test_arabic_brand_is_accepted(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload(['brand' => 'مرسيدس بنز', 'plate_number' => 'دم 1234']))
            ->assertCreated()
            ->assertJson(['success' => true]);
    }

    public function test_brands_with_hyphen_number_and_space_are_accepted(): void
    {
        $this->actAsCustomer();

        foreach (['BMW', 'MG', 'Mercedes-Benz', 'Land Rover', 'DS 7'] as $index => $brand) {
            $this->postJson('/api/vehicles', $this->payload([
                'brand' => $brand,
                'plate_number' => 'BR' . $index . ' 100',
            ]))->assertCreated();
        }

        $this->assertEquals(5, Vehicle::count());
    }

    public function test_brand_with_random_symbols_is_rejected(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload(['brand' => '!!!@@@###']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['brand']);

        $this->assertEquals(0, Vehicle::count());
    }

    public function test_too_short_plate_number_is_rejected(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload(['plate_number' => '12']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate_number']);
    }

    public function test_too_long_plate_number_is_rejected(): void
    {
        $this->actAsCustomer();

        // 10 محارف معتبرة بعد تجاهل المسافة، تتجاوز الحد الأقصى 9
        $this->postJson('/api/vehicles', $this->payload(['plate_number' => '1234567890']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate_number']);
    }

    public function test_plate_number_with_disallowed_symbols_is_rejected(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload(['plate_number' => 'AB@#1234']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate_number']);
    }

    public function test_future_year_beyond_limit_is_rejected(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload(['year' => (int) date('Y') + 5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['year']);
    }

    public function test_negative_current_km_is_rejected(): void
    {
        $this->actAsCustomer();

        $this->postJson('/api/vehicles', $this->payload(['current_km' => -10]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_km']);
    }

    public function test_unsupported_image_mime_is_rejected(): void
    {
        $this->actAsCustomer();

        $file = UploadedFile::fake()->create('vehicle.pdf', 100, 'application/pdf');

        $this->post('/api/vehicles', array_merge($this->payload(), ['image' => $file]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    public function test_oversized_image_is_rejected(): void
    {
        $this->actAsCustomer();

        // create() لا يحتاج امتداد GD (بخلاف image())، ويكفي لاختبار حد الحجم max:5120
        $file = UploadedFile::fake()->create('vehicle.jpg', 6000, 'image/jpeg');

        $this->post('/api/vehicles', array_merge($this->payload(), ['image' => $file]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);
    }

    // ===================== Update =====================

    public function test_updating_brand_only_without_plate_number_succeeds(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234']);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['brand' => 'Honda'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $fresh = $vehicle->fresh();
        $this->assertEquals('Honda', $fresh->brand);
        $this->assertEquals('Corolla', $fresh->model);
        $this->assertEquals('ABC 1234', $fresh->plate_number);
    }

    public function test_updating_model_only_succeeds(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234']);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['model' => 'Camry'])
            ->assertOk();

        $this->assertEquals('Camry', $vehicle->fresh()->model);
        $this->assertEquals('Toyota', $vehicle->fresh()->brand);
    }

    public function test_updating_current_km_only_succeeds(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234', 'current_km' => 100]);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['current_km' => 5000])
            ->assertOk();

        $fresh = $vehicle->fresh();
        $this->assertEquals(5000, $fresh->current_km);
        $this->assertEquals('Toyota', $fresh->brand);
        $this->assertEquals('ABC 1234', $fresh->plate_number);
    }

    public function test_not_sending_image_keeps_existing_image(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234', 'image' => 'vehicles/existing.jpg']);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['brand' => 'Honda'])
            ->assertOk();

        $this->assertEquals('vehicles/existing.jpg', $vehicle->fresh()->image);
    }

    public function test_owner_can_update_plate_number(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234']);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['plate_number' => 'XYZ 9999'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('XYZ 9999', $vehicle->fresh()->plate_number);
    }

    public function test_other_user_cannot_update_vehicle(): void
    {
        $owner = $this->makeUser();
        $vehicle = Vehicle::create(['user_id' => $owner->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234']);

        $other = $this->makeUser();
        Sanctum::actingAs($other);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['brand' => 'Honda'])
            ->assertStatus(400);

        $this->assertEquals('Toyota', $vehicle->fresh()->brand);
    }

    public function test_duplicate_plate_number_for_same_user_is_rejected(): void
    {
        $user = $this->actAsCustomer();
        Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234']);
        $second = Vehicle::create(['user_id' => $user->id, 'brand' => 'Kia', 'model' => 'Rio', 'year' => 2019, 'plate_number' => 'XYZ 5678']);

        $this->putJson("/api/vehicles/{$second->id}", ['plate_number' => 'ABC 1234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plate_number']);
    }

    public function test_updating_vehicle_without_changing_plate_number_does_not_fail_unique_check(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create(['user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'ABC 1234']);

        // إرسال رقم اللوحة نفسه صراحة ضمن التحديث يجب ألا يفشل بسبب مقارنته بنفسه
        $this->putJson("/api/vehicles/{$vehicle->id}", [
            'brand' => 'Toyota',
            'plate_number' => 'ABC 1234',
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_same_plate_number_allowed_across_different_users(): void
    {
        $owner1 = $this->makeUser();
        Vehicle::create(['user_id' => $owner1->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2020, 'plate_number' => 'SHARED 12']);

        $owner2 = $this->makeUser();
        Sanctum::actingAs($owner2);

        $this->postJson('/api/vehicles', $this->payload(['plate_number' => 'SHARED 12']))
            ->assertCreated()
            ->assertJson(['success' => true]);
    }

    public function test_unsent_field_keeps_its_current_value(): void
    {
        $user = $this->actAsCustomer();
        $vehicle = Vehicle::create([
            'user_id' => $user->id, 'brand' => 'Toyota', 'model' => 'Corolla', 'year' => 2018,
            'plate_number' => 'ABC 1234', 'current_km' => 500,
        ]);

        $this->putJson("/api/vehicles/{$vehicle->id}", ['model' => 'Yaris'])->assertOk();

        $fresh = $vehicle->fresh();
        $this->assertEquals('Yaris', $fresh->model);
        $this->assertEquals(2018, $fresh->year);
        $this->assertEquals(500, $fresh->current_km);
    }
}
