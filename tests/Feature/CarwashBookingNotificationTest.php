<?php

// للتذكير: هذا الملف يختبر الإشعارات الدائمة لتحولات حجز الغسيل (استلام، قبول، رفض، إلغاء).

namespace Tests\Feature;

use App\Models\CarWasher;
use App\Models\CarwashBooking;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class CarwashBookingNotificationTest extends TestCase
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
            'year' => 2020, 'plate_number' => 'CN-' . uniqid(),
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

    public function test_c1_new_booking_notifies_washer_user_only(): void
    {
        $washer = $this->makeApprovedWasher();
        $carWasher = CarWasher::where('user_id', $washer->id)->first();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $unrelated = $this->makeUser();
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/carwash_bookings', [
            'vehicle_id' => $vehicle->id,
            'car_washer_id' => $carWasher->id,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'service_type' => 'basic',
        ])->assertCreated()->assertJson(['success' => true]);

        $this->assertEquals(1, $washer->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $booking = CarwashBooking::first();
        $notification = $washer->notifications()->first();
        $this->assertEquals('carwash_booking_received', $notification->type);
        $this->assertEquals('تم استلام طلب غسيل جديد', $notification->data['title']);
        $this->assertEquals('تلقيت طلب غسيل جديد', $notification->data['body']);
        $this->assertEquals([
            'entity_type' => 'carwash_booking',
            'entity_id' => $booking->id,
            'action' => 'open_details',
            'status' => 'pending',
            'car_washer_id' => $carWasher->id,
        ], $notification->data['data']);
    }

    public function test_c2_accept_notifies_customer_only(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $washer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $carWasher = CarWasher::where('user_id', $washer->id)->first();
        $notification = $customer->notifications()->first();
        $this->assertEquals('carwash_booking_accepted', $notification->type);
        $this->assertEquals([
            'entity_type' => 'carwash_booking',
            'entity_id' => $booking->id,
            'action' => 'open_details',
            'status' => 'accepted',
            'car_washer_id' => $carWasher->id,
        ], $notification->data['data']);
    }

    public function test_c3_reject_notifies_customer_with_reason(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/reject", [
            'rejection_reason' => 'مواعيد ممتلئة',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $notification = $customer->notifications()->first();
        $this->assertEquals('carwash_booking_rejected', $notification->type);
        $this->assertEquals('cancelled', $notification->data['data']['status']);
        $this->assertArrayHasKey('reason', $notification->data['data']);
        $this->assertEquals('مواعيد ممتلئة', $notification->data['data']['reason']);
    }

    public function test_c3_reject_includes_null_reason_key_when_absent(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/reject", [])
            ->assertOk();

        $notification = $customer->notifications()->first();
        $this->assertArrayHasKey('reason', $notification->data['data']);
        $this->assertNull($notification->data['data']['reason']);
    }

    public function test_c4_in_progress_notifies_customer_only(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($washer);

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $washer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());
        $this->assertEquals('carwash_booking_in_progress', $customer->notifications()->first()->type);
    }

    public function test_c5_completed_from_in_progress_notifies_customer_only(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'in_progress');
        Sanctum::actingAs($washer);

        $this->patchJson("/api/car_washer/bookings/{$booking->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals('carwash_booking_completed', $customer->notifications()->first()->type);
    }

    public function test_c6_cancel_of_pending_booking_notifies_washer(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $booking->fresh()->status);
        $this->assertEquals(1, $washer->notifications()->count());
        $notification = $washer->notifications()->first();
        $this->assertEquals('carwash_booking_cancelled_by_customer', $notification->type);
        $this->assertEquals('تغيّرت خططي', $notification->data['data']['reason']);
    }

    public function test_c6_cancel_of_accepted_booking_notifies_washer(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertOk();

        $this->assertEquals(1, $washer->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());
        $this->assertEquals('carwash_booking_cancelled_by_customer', $washer->notifications()->first()->type);
    }

    public function test_notifications_skip_safely_when_washer_user_soft_deleted(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');
        $washer->delete();
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/cancel", [
            'cancellation_reason' => 'تغيّرت خططي',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $booking->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_accept_succeeds_when_notification_broadcast_fails(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'pending');

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($washer);

        $this->postJson("/api/car_washer/bookings/{$booking->id}/accept")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $booking->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_rating_does_not_create_notification(): void
    {
        $washer = $this->makeApprovedWasher();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $booking = $this->makeBooking($customer, $vehicle, $washer, 'completed');
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/carwash_bookings/{$booking->id}/rate", [
            'rating' => 5, 'review' => 'ممتاز',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(0, DatabaseNotification::count());
    }
}
