<?php

// للتذكير: هذا الملف يختبر أن حجز الغسيل يُنشأ بسعر وحالة محسوبة من المغسلة وليس من الطلب.

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CarwashBookingCreateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function actAsCustomerWithVehicle(): int
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2021, 'plate_number' => 'CW-' . uniqid(),
        ]);
        Sanctum::actingAs($customer);

        return $vehicle->id;
    }

    private function makeCarWasher(array $servicePrices): CarWasher
    {
        $u = $this->makeUser();

        return CarWasher::create([
            'user_id' => $u->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved',
            'is_available' => true, 'service_prices' => $servicePrices,
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
        ])->assertStatus(500);

        $this->assertEquals(0, CarwashBooking::count());
    }
}
