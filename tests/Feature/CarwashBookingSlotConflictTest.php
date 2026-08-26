<?php

// للتذكير: هذا الملف يختبر منع حجزين نشطين لنفس المغسلة في نفس الموعد بالضبط (سلامة التعارض على الفتحة الزمنية).

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CarwashBookingSlotConflictTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeApprovedWasher(array $servicePrices = ['basic' => 10]): CarWasher
    {
        $washerUser = $this->makeUserWithRole('car-washer');

        return CarWasher::create([
            'user_id' => $washerUser->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'service_prices' => $servicePrices,
        ]);
    }

    private function actAsCustomerWithVehicle(): int
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2021, 'plate_number' => 'SC-' . uniqid(),
        ]);
        Sanctum::actingAs($customer);

        return $vehicle->id;
    }

    private function bookPayload(CarWasher $carWasher, int $vehicleId, string $scheduledAt): array
    {
        return [
            'vehicle_id' => $vehicleId,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => $scheduledAt,
            'service_type' => 'basic',
        ];
    }

    public function test_first_booking_for_washer_and_slot_succeeds(): void
    {
        $carWasher = $this->makeApprovedWasher();
        $vehicleId = $this->actAsCustomerWithVehicle();
        $slot = now()->addDay()->format('Y-m-d H:i:s');

        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher, $vehicleId, $slot))
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, CarwashBooking::count());
    }

    public function test_second_booking_for_same_washer_and_exact_slot_is_rejected(): void
    {
        $carWasher = $this->makeApprovedWasher();
        $slot = now()->addDay()->format('Y-m-d H:i:s');

        $vehicleId1 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher, $vehicleId1, $slot))
            ->assertCreated();

        $vehicleId2 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher, $vehicleId2, $slot))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);

        $this->assertEquals(1, CarwashBooking::count());
    }

    public function test_same_slot_with_different_washer_is_allowed(): void
    {
        $carWasher1 = $this->makeApprovedWasher();
        $carWasher2 = $this->makeApprovedWasher();
        $slot = now()->addDay()->format('Y-m-d H:i:s');

        $vehicleId1 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher1, $vehicleId1, $slot))
            ->assertCreated();

        $vehicleId2 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher2, $vehicleId2, $slot))
            ->assertCreated();

        $this->assertEquals(2, CarwashBooking::count());
    }

    public function test_cancelled_booking_does_not_block_new_booking_for_same_slot(): void
    {
        $carWasher = $this->makeApprovedWasher();
        $slot = now()->addDay()->format('Y-m-d H:i:s');

        $vehicleId1 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher, $vehicleId1, $slot))
            ->assertCreated();

        $firstBooking = CarwashBooking::first();
        Sanctum::actingAs(User::find($firstBooking->user_id));
        $this->postJson("/api/customer/carwash_bookings/{$firstBooking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertOk();

        $vehicleId2 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload($carWasher, $vehicleId2, $slot))
            ->assertCreated()
            ->assertJson(['success' => true]);

        $this->assertEquals(2, CarwashBooking::count());
        $this->assertEquals('cancelled', $firstBooking->fresh()->status);
    }

    public function test_normal_booking_flow_still_succeeds_for_distinct_slots(): void
    {
        $carWasher = $this->makeApprovedWasher();

        $vehicleId1 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload(
            $carWasher, $vehicleId1, now()->addDay()->format('Y-m-d H:i:s')
        ))->assertCreated();

        $vehicleId2 = $this->actAsCustomerWithVehicle();
        $this->postJson('/api/customer/carwash_bookings', $this->bookPayload(
            $carWasher, $vehicleId2, now()->addDays(2)->format('Y-m-d H:i:s')
        ))->assertCreated();

        $this->assertEquals(2, CarwashBooking::count());
    }
}
