<?php

// للتذكير: هذا الملف يختبر Adaptive Progressive Radius Expansion لطلبات الوقود (Available/Notification/Accept/Recovery).

namespace Tests\Feature;

use App\Jobs\ExpandDispatchRadius;
use App\Jobs\MaxRadiusRecheckJob;
use App\Models\DispatchNotificationRecipient;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelDispatchRadiusExpansionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private const BASE_LAT = 33.5000;
    private const BASE_LNG = 36.3000;
    private const KM_TO_LAT_DEGREES = 1 / 111.194927;

    private int $customerId;
    private int $customerVehicleId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ExpandDispatchRadius::class, MaxRadiusRecheckJob::class]);
    }

    private function makeProviderAtDistance(float $km, array $overrides = []): FuelProvider
    {
        $u = $this->makeUserWithRole('fuel-provider');

        return FuelProvider::create(array_merge([
            'user_id' => $u->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => 'دمشق', 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'latitude' => self::BASE_LAT + $km * self::KM_TO_LAT_DEGREES,
            'longitude' => self::BASE_LNG,
            'fuel_types' => ['95', '98', 'diesel'],
        ], $overrides));
    }

    private function ensureCustomer(): void
    {
        if (isset($this->customerId)) {
            return;
        }

        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'DR-' . uniqid(),
        ]);
        $this->customerId = $customer->id;
        $this->customerVehicleId = $vehicle->id;
    }

    private function createOrderViaApi(array $overrides = []): FuelOrder
    {
        $this->ensureCustomer();
        $customer = User::find($this->customerId);
        Sanctum::actingAs($customer);

        $this->postJson('/api/customer/fuel_orders/emergency', array_merge([
            'vehicle_id' => $this->customerVehicleId,
            'fuel_type' => '95',
            'amount' => 20,
            'delivery_latitude' => self::BASE_LAT,
            'delivery_longitude' => self::BASE_LNG,
            'city' => 'دمشق',
        ], $overrides))->assertCreated();

        return FuelOrder::latest('id')->first();
    }

    private function availableIdsFor(FuelProvider $provider): array
    {
        Sanctum::actingAs(User::find($provider->user_id));
        $res = $this->getJson('/api/fuel_provider/available_orders')->assertOk();

        return array_map('intval', array_column($res->json('data'), 'id'));
    }

    private function notifiedCount(FuelOrder $order, FuelProvider $provider): int
    {
        return DispatchNotificationRecipient::where('service_type', 'fuel')
            ->where('request_id', $order->id)
            ->where('recipient_type', 'fuel_provider')
            ->where('recipient_id', $provider->id)
            ->count();
    }

    private function accept(FuelProvider $provider, FuelOrder $order)
    {
        Sanctum::actingAs(User::find($provider->user_id));

        return $this->postJson("/api/fuel_provider/orders/{$order->id}/accept", []);
    }

    public function test_initial_radius_only_near_provider_visible_notified_acceptable(): void
    {
        $a = $this->makeProviderAtDistance(7);
        $b = $this->makeProviderAtDistance(16);
        $c = $this->makeProviderAtDistance(28, ['city' => 'Daraa']);
        $d = $this->makeProviderAtDistance(37);
        $e = $this->makeProviderAtDistance(48);
        $f = $this->makeProviderAtDistance(65);

        $order = $this->createOrderViaApi();

        $this->assertEquals(10, $order->fresh()->current_radius_km);

        $this->assertContains($order->id, $this->availableIdsFor($a));
        foreach ([$b, $c, $d, $e, $f] as $p) {
            $this->assertNotContains($order->id, $this->availableIdsFor($p));
        }

        $this->assertEquals(1, $this->notifiedCount($order, $a));
        foreach ([$b, $c, $d, $e, $f] as $p) {
            $this->assertEquals(0, $this->notifiedCount($order, $p));
        }

        $this->accept($a, $order)->assertOk()->assertJson(['success' => true]);
    }

    public function test_progressive_expansion_notifies_only_newly_eligible_each_stage(): void
    {
        $a = $this->makeProviderAtDistance(7);
        $b = $this->makeProviderAtDistance(16);
        $c = $this->makeProviderAtDistance(28);
        $d = $this->makeProviderAtDistance(37);
        $e = $this->makeProviderAtDistance(48);

        $order = $this->createOrderViaApi();
        $service = app(FuelOrderService::class);

        $this->assertEquals(10, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $a));

        $service->expandDispatchRadius($order->id, 10);
        $this->assertEquals(20, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $a));
        $this->assertEquals(1, $this->notifiedCount($order, $b));
        $this->assertContains($order->id, $this->availableIdsFor($a));
        $this->assertContains($order->id, $this->availableIdsFor($b));

        $service->expandDispatchRadius($order->id, 20);
        $this->assertEquals(30, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $c));

        $service->expandDispatchRadius($order->id, 30);
        $this->assertEquals(40, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $d));

        $service->expandDispatchRadius($order->id, 40);
        $this->assertEquals(50, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $e));

        Queue::assertPushed(ExpandDispatchRadius::class);
    }

    public function test_empty_radii_are_skipped_immediately(): void
    {
        $a = $this->makeProviderAtDistance(34);
        $b = $this->makeProviderAtDistance(38);

        $order = $this->createOrderViaApi();

        $this->assertEquals(40, $order->fresh()->current_radius_km);
        $this->assertContains($order->id, $this->availableIdsFor($a));
        $this->assertContains($order->id, $this->availableIdsFor($b));
        $this->assertEquals(1, $this->notifiedCount($order, $a));
        $this->assertEquals(1, $this->notifiedCount($order, $b));
    }

    public function test_governorate_has_zero_effect_on_dispatch(): void
    {
        $damascus = $this->makeProviderAtDistance(8, ['city' => 'Damascus']);
        $daraa = $this->makeProviderAtDistance(18, ['city' => 'Daraa']);

        $order = $this->createOrderViaApi(['city' => 'Daraa']);
        $service = app(FuelOrderService::class);

        $this->assertEquals(10, $order->fresh()->current_radius_km);
        $this->assertContains($order->id, $this->availableIdsFor($damascus));
        $this->assertNotContains($order->id, $this->availableIdsFor($daraa));

        $service->expandDispatchRadius($order->id, 10);
        $this->assertEquals(20, $order->fresh()->current_radius_km);
        $this->assertContains($order->id, $this->availableIdsFor($daraa));
    }

    public function test_boundary_distance_is_inclusive(): void
    {
        $inside = $this->makeProviderAtDistance(9.9);
        $outside = $this->makeProviderAtDistance(10.1);

        $order = $this->createOrderViaApi();

        $this->assertEquals(10, $order->fresh()->current_radius_km);
        $this->assertContains($order->id, $this->availableIdsFor($inside));
        $this->assertNotContains($order->id, $this->availableIdsFor($outside));
    }

    public function test_direct_api_accept_denied_then_allowed_after_expansion(): void
    {
        $near = $this->makeProviderAtDistance(16);
        $far = $this->makeProviderAtDistance(35);

        $order = $this->createOrderViaApi();
        app(FuelOrderService::class)->expandDispatchRadius($order->id, 10);
        $this->assertEquals(20, $order->fresh()->current_radius_km);

        $this->accept($far, $order)
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'OUT_OF_SERVICE_RANGE']);

        app(FuelOrderService::class)->expandDispatchRadius($order->id, 20);
        app(FuelOrderService::class)->expandDispatchRadius($order->id, 30);
        $this->assertEquals(40, $order->fresh()->current_radius_km);

        $this->accept($far, $order)->assertOk()->assertJson(['success' => true]);
    }

    public function test_accept_stops_a_stale_expansion_job_from_doing_anything(): void
    {
        $a = $this->makeProviderAtDistance(7);
        $order = $this->createOrderViaApi();

        $this->accept($a, $order)->assertOk();
        $this->assertEquals('accepted', $order->fresh()->status);

        app(FuelOrderService::class)->expandDispatchRadius($order->id, 10);

        $this->assertEquals(10, $order->fresh()->current_radius_km);
        $this->assertEquals('accepted', $order->fresh()->status);
    }

    public function test_expansion_job_retry_is_idempotent(): void
    {
        $a = $this->makeProviderAtDistance(7);
        $b = $this->makeProviderAtDistance(16);
        $order = $this->createOrderViaApi();

        app(FuelOrderService::class)->expandDispatchRadius($order->id, 10);
        $this->assertEquals(20, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $b));

        app(FuelOrderService::class)->expandDispatchRadius($order->id, 10);
        $this->assertEquals(20, $order->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($order, $b));
    }

    public function test_two_eligible_providers_racing_only_one_wins(): void
    {
        $a = $this->makeProviderAtDistance(5);
        $b = $this->makeProviderAtDistance(6);
        $order = $this->createOrderViaApi();

        $this->accept($a, $order)->assertOk();
        $this->accept($b, $order)->assertStatus(500)->assertJson(['success' => false]);

        $this->assertEquals(
            FuelProvider::where('user_id', $a->user_id)->first()->id,
            $order->fresh()->fuel_provider_id
        );
    }

    public function test_max_radius_exhaustion_then_recheck_discovers_new_provider(): void
    {
        $order = $this->createOrderViaApi();

        $this->assertEquals(70, $order->fresh()->current_radius_km);
        Queue::assertPushed(MaxRadiusRecheckJob::class, 1);

        $late = $this->makeProviderAtDistance(12);

        app(FuelOrderService::class)->recheckMaxRadius($order->id);

        $this->assertEquals(70, $order->fresh()->current_radius_km);
        $this->assertContains($order->id, $this->availableIdsFor($late));
        $this->assertEquals(1, $this->notifiedCount($order, $late));
        $this->accept($late, $order)->assertOk();
    }
}
