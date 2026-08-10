<?php

// للتذكير: هذا الملف يختبر تعارض انتقالات حجز الغسيل (accept/reject/status/cancel) تحت القفل والتكرار.
// الاختبارات هنا تسلسلية وتثبت الثبات المنطقي (invariants) فقط؛ وهي لا تحاكي تزامناً حقيقياً بين طلبين HTTP.
// الحماية الفعلية من السباقات تأتي من lockForUpdate() على مستوى قاعدة البيانات.

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CarwashBookingStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeApprovedWasher(): User
    {
        $washerUser = $this->makeUserWithRole('car-washer');
        CarWasher::create([
            'user_id' => $washerUser->id, 'shop_name' => 'CleanCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'service_prices' => ['basic' => 10, 'premium' => 50, 'vip' => 20],
        ]);

        return $washerUser;
    }

    private function makeCustomerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'CS-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function makeBooking($customer, $vehicle, User $washerUser, string $status = 'pending'): CarwashBooking
    {
        $carWasher = CarWasher::where('user_id', $washerUser->id)->first();

        return CarwashBooking::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'car_washer_id' => $carWasher->id, 'scheduled_at' => now()->addDay(),
            'service_type' => 'basic', 'price' => 10, 'status' => $status,
        ]);
    }

    public function test_accepted_to_completed_remains_supported(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'accepted');
        Sanctum::actingAs($washer);

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('completed', $booking->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals('carwash_booking_completed', $customer->notifications()->first()->type);
    }

    public function test_accept_then_reject_fails_and_adds_no_notification(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")->assertOk();
        $this->assertEquals(1, $customer->notifications()->count());

        $this->postJson("/api/car_washer/bookings/{$booking->id}/reject")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('accepted', $booking->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_reject_then_accept_fails_and_adds_no_notification(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/reject")->assertOk();
        $this->assertEquals(1, $customer->notifications()->count());

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $booking->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_cancel_then_accept_fails(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertOk();
        $this->assertEquals(1, $washer->notifications()->count());

        Sanctum::actingAs($washer);
        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $booking->fresh()->status);
        $this->assertEquals(0, $customer->notifications()->count());
    }

    public function test_cancel_then_reject_fails(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertOk();

        Sanctum::actingAs($washer);
        $this->postJson("/api/car_washer/bookings/{$booking->id}/reject")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('cancelled', $booking->fresh()->status);
    }

    public function test_in_progress_then_customer_cancel_fails(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'in_progress');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertStatus(500)->assertJson(['success' => false]);

        $this->assertEquals('in_progress', $booking->fresh()->status);
        $this->assertEquals(0, $washer->notifications()->count());
    }

    public function test_repeated_in_progress_does_not_duplicate_notification(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'accepted');
        Sanctum::actingAs($washer);

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'in_progress'])
            ->assertOk();
        $this->assertEquals(1, $customer->notifications()->count());

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_repeated_completed_does_not_duplicate_notification(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'in_progress');
        Sanctum::actingAs($washer);

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertOk();
        $this->assertEquals(1, $customer->notifications()->count());

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('completed', $booking->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_completed_to_in_progress_is_rejected(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'completed');
        Sanctum::actingAs($washer);

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('completed', $booking->fresh()->status);
        $this->assertEquals(0, $customer->notifications()->count());
    }

    public function test_unauthorized_washer_cannot_update_others_booking(): void
    {
        $owner = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $owner, 'pending');
        $other = $this->makeApprovedWasher();
        Sanctum::actingAs($other);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals('pending', $booking->fresh()->status);
        $this->assertEquals(0, $customer->notifications()->count());
    }
}
