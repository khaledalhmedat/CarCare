<?php

// للتذكير: هذا الملف يختبر أن حجز الغسيل يُنشأ بسعر وحالة محسوبة من المغسلة وليس من الطلب.

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CarwashBookingCreateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function actAsCustomerWithVehicle(): int
    {
        [, $vehicleId] = $this->actAsCustomerAndGetVehicle();

        return $vehicleId;
    }

    private function actAsCustomerAndGetVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2021, 'plate_number' => 'CW-' . uniqid(),
        ]);
        Sanctum::actingAs($customer);

        return [$customer, $vehicle->id];
    }

    private function makeCarWasher(array $servicePrices): CarWasher
    {
        return $this->makeCarWasherWithState($servicePrices, 'approved', true);
    }

    private function makeCarWasherWithState(array $servicePrices, string $status, bool $isAvailable): CarWasher
    {
        $u = $this->makeUser();

        return CarWasher::create([
            'user_id' => $u->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => $status,
            'is_available' => $isAvailable, 'service_prices' => $servicePrices,
        ]);
    }

    public function test_booking_price_and_status_are_calculated_server_side(): void
    {
        $vehicleId = $this->actAsCustomerWithVehicle();
        $carWasher = $this->makeCarWasher(['vip' => 20, 'basic' => 10, 'premium' => 50]);

        $response = $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $vehicleId,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'vip',
            'notes' => 'test',
            // a client-supplied price must be ignored by the backend
            'price' => 999999,
        ]);

        $response->assertCreated()
            ->assertJson([
                'success' => true,
                'data' => [
                    'price' => 20,
                    'status' => 'pending',
                    'status_text' => 'قيد الانتظار',
                    'can_cancel' => true,
                ],
            ]);

        $booking = CarwashBooking::first();
        $this->assertEquals(20, $booking->price);
        $this->assertEquals('pending', $booking->status);
    }

    public function test_booking_rejected_when_service_type_not_offered_by_car_washer(): void
    {
        $vehicleId = $this->actAsCustomerWithVehicle();
        $carWasher = $this->makeCarWasher(['basic' => 10]);

        $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $vehicleId,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'vip',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['service_type']);

        $this->assertEquals(0, CarwashBooking::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_booking_rejected_when_vehicle_belongs_to_another_user(): void
    {
        $owner = $this->makeUser();
        $foreignVehicle = Vehicle::create([
            'user_id' => $owner->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2021, 'plate_number' => 'CW-' . uniqid(),
        ]);

        $customer = $this->makeUser();
        Sanctum::actingAs($customer);
        $carWasher = $this->makeCarWasher(['basic' => 10]);

        $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $foreignVehicle->id,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'basic',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id']);

        $this->assertEquals(0, CarwashBooking::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_booking_rejected_when_customer_tries_to_book_own_car_wash(): void
    {
        [$customer, $vehicleId] = $this->actAsCustomerAndGetVehicle();
        $carWasher = CarWasher::create([
            'user_id' => $customer->id, 'shop_name' => 'MyOwnCarWash', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved',
            'is_available' => true, 'service_prices' => ['basic' => 10],
        ]);

        $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $vehicleId,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'basic',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['car_washer_id']);

        $this->assertEquals(0, CarwashBooking::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_booking_rejected_when_car_washer_is_pending_approval(): void
    {
        $vehicleId = $this->actAsCustomerWithVehicle();
        $carWasher = $this->makeCarWasherWithState(['basic' => 10], 'pending', true);

        $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $vehicleId,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'basic',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['car_washer_id']);

        $this->assertEquals(0, CarwashBooking::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_booking_rejected_when_car_washer_is_rejected_or_suspended(): void
    {
        foreach (['rejected', 'suspended'] as $status) {
            $vehicleId = $this->actAsCustomerWithVehicle();
            $carWasher = $this->makeCarWasherWithState(['basic' => 10], $status, true);

            $this->postJson('/api/customer/carwash_bookings', [
                'vehicle_id' => $vehicleId,
                'car_washer_id' => $carWasher->id,
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'service_type' => 'basic',
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['car_washer_id']);
        }

        $this->assertEquals(0, CarwashBooking::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_booking_rejected_when_car_washer_is_unavailable(): void
    {
        $vehicleId = $this->actAsCustomerWithVehicle();
        $carWasher = $this->makeCarWasherWithState(['basic' => 10], 'approved', false);

        $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $vehicleId,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'basic',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['car_washer_id']);

        $this->assertEquals(0, CarwashBooking::count());
        $this->assertEquals(0, DatabaseNotification::count());
    }
}
