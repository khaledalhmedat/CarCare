<?php

// للتذكير: هذا الملف يختبر الإشعارات الدائمة لتحولات طلب الوقود (قبول، تحديث حالة، إلغاء).

namespace Tests\Feature;

use App\Models\FuelLog;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelOrderNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([\App\Jobs\ExpandDispatchRadius::class, \App\Jobs\MaxRadiusRecheckJob::class]);
    }

    private function makeApprovedProvider(): User
    {
        $providerUser = $this->makeUserWithRole('fuel-provider');
        FuelProvider::create([
            'user_id' => $providerUser->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'fuel_types' => ['95', '98', 'diesel'],
            'latitude' => 33.5460, 'longitude' => 36.3249,
        ]);

        return $providerUser;
    }

    private function makeCustomerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FN-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function makePendingOrder($customer, $vehicle): FuelOrder
    {
        return FuelOrder::create(array_merge([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'fuel_type' => '95', 'amount' => 20, 'delivery_address' => 'دمشق',
            'delivery_latitude' => 33.5460, 'delivery_longitude' => 36.3249,
            'status' => 'pending',
        ], $this->eligibleRadiusState()));
    }

    private function makeOrderForProvider($customer, $vehicle, User $providerUser, string $status = 'accepted'): FuelOrder
    {
        $fuelProvider = FuelProvider::where('user_id', $providerUser->id)->first();

        return FuelOrder::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'fuel_type' => '95', 'amount' => 20, 'delivery_address' => 'دمشق',
            'status' => $status, 'fuel_provider_id' => $fuelProvider->id,
            'total_price' => 50, 'accepted_at' => now(),
        ]);
    }

    public function test_f1_accept_notifies_customer_only(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makePendingOrder($customer, $vehicle);
        $unrelated = $this->makeUser();
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [
            'estimated_arrival_minutes' => 45,
            'notes' => 'سآتي عبر الطريق الرئيسي',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $provider->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        // القيمتان يجب أن تُخزَّنا حرفياً في صف fuel_orders
        $fresh = $order->fresh();
        $this->assertEquals(45, $fresh->estimated_arrival_minutes);
        $this->assertEquals('سآتي عبر الطريق الرئيسي', $fresh->provider_notes);

        $fuelProvider = FuelProvider::where('user_id', $provider->id)->first();
        $notification = $customer->notifications()->first();
        $this->assertEquals('fuel_order_accepted', $notification->type);
        $this->assertEquals('تم قبول طلب الوقود', $notification->data['title']);
        $this->assertEquals('تم قبول طلب الوقود الخاص بك، والمزود في طريقه إليك', $notification->data['body']);
        $this->assertEquals('fuel_order', $notification->data['data']['entity_type']);
        $this->assertEquals($order->id, $notification->data['data']['entity_id']);
        $this->assertEquals('open_details', $notification->data['data']['action']);
        $this->assertEquals('accepted', $notification->data['data']['status']);
        $this->assertEquals($fuelProvider->id, $notification->data['data']['fuel_provider_id']);
        // القيمة الفعلية المخزّنة، وليس مجرد وجود المفتاح
        $this->assertEquals(45, $notification->data['data']['estimated_arrival_minutes']);
    }

    public function test_accept_without_optional_fields_stores_null_and_notification_stays_safe(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makePendingOrder($customer, $vehicle);
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $fresh = $order->fresh();
        $this->assertNull($fresh->estimated_arrival_minutes);
        $this->assertNull($fresh->provider_notes);

        $this->assertEquals(1, $customer->notifications()->count());
        $notification = $customer->notifications()->first();
        $this->assertEquals('fuel_order_accepted', $notification->type);
        $this->assertNull($notification->data['data']['estimated_arrival_minutes']);
    }

    public function test_second_accept_attempt_creates_no_additional_notification_or_change(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makePendingOrder($customer, $vehicle);
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [
            'estimated_arrival_minutes' => 30,
        ])->assertOk();

        // controller هذا الإجراء لا يغلّف الاستثناء، فمحاولة قبول طلب لم يعد pending تُعالَج عبر معالجة الأخطاء العامة وتُرجع 500
        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [
            'estimated_arrival_minutes' => 90,
        ])->assertStatus(500)->assertJson(['success' => false]);

        $this->assertEquals(30, $order->fresh()->estimated_arrival_minutes);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_f2_in_progress_notifies_customer_only_with_canonical_payload(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $provider->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $fuelProvider = FuelProvider::where('user_id', $provider->id)->first();
        $notification = $customer->notifications()->first();
        $this->assertEquals('fuel_order_in_progress', $notification->type);
        $this->assertEquals([
            'entity_type' => 'fuel_order',
            'entity_id' => $order->id,
            'action' => 'open_details',
            'status' => 'in_progress',
            'fuel_provider_id' => $fuelProvider->id,
        ], $notification->data['data']);
    }

    public function test_f3_completed_notifies_customer_only_with_one_fuel_log(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'in_progress');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertOk();

        $this->assertEquals(1, FuelLog::where('fuel_order_id', $order->id)->count());
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $provider->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('fuel_order_completed', $notification->type);
        $this->assertEquals('completed', $notification->data['data']['status']);
    }

    public function test_repeated_transition_creates_no_duplicate_notification(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        Sanctum::actingAs($provider);

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertOk();
        $this->assertEquals(1, $customer->notifications()->count());

        $this->patchJson("/api/fuel_provider/orders/{$order->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500);

        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_f4_customer_cancel_of_accepted_order_notifies_provider_user_only(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/fuel_orders/cancel/{$order->id}", [
            'cancellation_reason' => 'لم أعد بحاجة للوقود',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(1, $provider->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $fuelProvider = FuelProvider::where('user_id', $provider->id)->first();
        $notification = $provider->notifications()->first();
        $this->assertEquals('fuel_order_cancelled_by_customer', $notification->type);
        $this->assertEquals([
            'entity_type' => 'fuel_order',
            'entity_id' => $order->id,
            'action' => 'open_details',
            'status' => 'cancelled',
            'reason' => 'لم أعد بحاجة للوقود',
            'fuel_provider_id' => $fuelProvider->id,
        ], $notification->data['data']);
    }

    public function test_cancel_of_unassigned_pending_order_creates_no_notification(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makePendingOrder($customer, $vehicle);
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/fuel_orders/cancel/{$order->id}", [
            'cancellation_reason' => 'لم أعد بحاجة للوقود',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_f5_provider_cancel_notifies_customer_only_and_reopens_order(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'عطل في السيارة',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->fuel_provider_id);
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $provider->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('fuel_order_reopened_after_provider_cancel', $notification->type);
        $this->assertEquals('pending', $notification->data['data']['status']);
        $this->assertEquals('عطل في السيارة', $notification->data['data']['reason']);
    }

    public function test_f4_skips_safely_when_fuel_provider_profile_soft_deleted(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        FuelProvider::where('user_id', $provider->id)->first()->delete();
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/fuel_orders/cancel/{$order->id}", [
            'cancellation_reason' => 'لم أعد بحاجة للوقود',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_f4_skips_safely_when_provider_user_soft_deleted(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        $provider->delete();
        Sanctum::actingAs($customer);

        $this->postJson("/api/customer/fuel_orders/cancel/{$order->id}", [
            'cancellation_reason' => 'لم أعد بحاجة للوقود',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_f5_skips_safely_when_customer_soft_deleted(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        $customer->delete();
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'عطل في السيارة',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_accept_succeeds_when_notification_broadcast_fails_and_notification_persists(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makePendingOrder($customer, $vehicle);

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $order->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_emergency_order_creation_does_not_create_persistent_notification(): void
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'FN-' . uniqid(),
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/fuel_orders/emergency', [
            'vehicle_id' => $vehicle->id,
            'fuel_type' => '95',
            'amount' => 20,
            'delivery_latitude' => 33.54,
            'delivery_longitude' => 36.32,
            'city' => 'دمشق',
        ])->assertCreated()->assertJson(['success' => true]);

        $this->assertEquals(0, DatabaseNotification::count());
    }

    public function test_location_update_does_not_create_persistent_notification(): void
    {
        $provider = $this->makeApprovedProvider();
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeOrderForProvider($customer, $vehicle, $provider, 'accepted');
        Sanctum::actingAs($provider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/location", [
            'latitude' => 33.5457764, 'longitude' => 36.3250658,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(0, DatabaseNotification::count());
    }
}
