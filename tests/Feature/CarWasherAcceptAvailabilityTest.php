<?php

// للتذكير: هذا الملف يختبر أن مغسلة غير متاحة لا يمكنها قبول حجز حتى لو كان الحجز موجوداً مسبقاً وهي متاحة.

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CarWasherAcceptAvailabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeWasher(bool $isAvailable): User
    {
        $washerUser = $this->makeUserWithRole('car-washer');
        CarWasher::create([
            'user_id' => $washerUser->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => $isAvailable,
            'service_prices' => ['basic' => 10],
        ]);

        return $washerUser;
    }

    private function makeBooking(User $washerUser): CarwashBooking
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'CA-' . uniqid(),
        ]);
        $carWasher = CarWasher::where('user_id', $washerUser->id)->first();

        return CarwashBooking::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'car_washer_id' => $carWasher->id, 'scheduled_at' => now()->addDay(),
            'service_type' => 'basic', 'price' => 10, 'status' => 'pending',
        ]);
    }

    public function test_available_washer_can_accept_valid_booking(): void
    {
        $washer = $this->makeWasher(true);
        $booking = $this->makeBooking($washer);
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $booking->fresh()->status);
    }

    public function test_unavailable_washer_cannot_accept_booking(): void
    {
        // الحجز أُنشئ أثناء توفر المغسلة، ثم تحوّلت المغسلة لغير متاحة قبل القبول
        $washer = $this->makeWasher(true);
        $booking = $this->makeBooking($washer);

        CarWasher::where('user_id', $washer->id)->update(['is_available' => false]);
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('pending', $booking->fresh()->status);
    }

    public function test_rejected_acceptance_leaves_booking_status_unchanged(): void
    {
        $washer = $this->makeWasher(false);
        $booking = $this->makeBooking($washer);
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")->assertStatus(500);

        $fresh = $booking->fresh();
        $this->assertEquals('pending', $fresh->status);
        $this->assertNull($fresh->accepted_at);
    }
}
