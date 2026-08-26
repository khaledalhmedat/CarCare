<?php

// للتذكير: هذا الملف يختبر إعادة إعلان طلب الوقود لمزودين مؤهلين آخرين بعد إلغاء المزود الحالي،
// دون رمي استثناء عند غياب مزود مستهدف، ودون إشعار المزود الذي ألغى بنفسه.

namespace Tests\Feature;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FuelProviderCancelReannounceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public static array $broadcastCaptures = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$broadcastCaptures = [];
    }

    private function useSpyBroadcaster(): void
    {
        Broadcast::extend('spy', function () {
            return new class implements Broadcaster {
                public function auth($request)
                {
                }

                public function validAuthenticationResponse($request, $result)
                {
                    return $result;
                }

                public function broadcast(array $channels, $event, array $payload = [])
                {
                    FuelProviderCancelReannounceTest::$broadcastCaptures[] = [
                        'channels' => array_map(fn ($c) => (string) $c, $channels),
                        'event' => $event,
                        'payload' => $payload,
                    ];
                }
            };
        });

        config([
            'broadcasting.connections.spy' => ['driver' => 'spy'],
            'broadcasting.default' => 'spy',
        ]);
    }

    private function makeApprovedProvider(float $lat, float $lng, string $city = 'دمشق'): User
    {
        $providerUser = $this->makeUserWithRole('fuel-provider');
        FuelProvider::create([
            'user_id' => $providerUser->id, 'company_name' => 'FuelCo', 'phone' => '05',
            'city' => $city, 'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'fuel_types' => ['95', '98', 'diesel'],
            'latitude' => $lat, 'longitude' => $lng,
        ]);

        return $providerUser;
    }

    private function makeCustomerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'FC-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function makeAcceptedOrder($customer, $vehicle, User $providerUser, float $lat, float $lng, string $city): FuelOrder
    {
        $fuelProvider = FuelProvider::where('user_id', $providerUser->id)->first();

        return FuelOrder::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'fuel_type' => '95', 'amount' => 20, 'delivery_address' => $city,
            'delivery_latitude' => $lat, 'delivery_longitude' => $lng, 'city' => $city,
            'status' => 'accepted', 'fuel_provider_id' => $fuelProvider->id,
            'total_price' => 50, 'accepted_at' => now(),
        ]);
    }

    public function test_provider_cancellation_reannounces_to_other_eligible_provider(): void
    {
        $this->useSpyBroadcaster();

        $cancellingProvider = $this->makeApprovedProvider(33.5460, 36.3249);
        $otherProvider = $this->makeApprovedProvider(33.5470, 36.3260);
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeAcceptedOrder($customer, $vehicle, $cancellingProvider, 33.5460, 36.3249, 'دمشق');
        Sanctum::actingAs($cancellingProvider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'عطل في السيارة',
        ])->assertOk()->assertJson(['success' => true]);

        $reannounce = array_values(array_filter(
            self::$broadcastCaptures,
            fn ($c) => $c['event'] === 'new-emergency-fuel-order'
        ));

        $this->assertCount(1, $reannounce);
        $this->assertSame(["fuel-provider.{$otherProvider->id}"], $reannounce[0]['channels']);
        $this->assertSame($order->id, $reannounce[0]['payload']['order_id']);

        $this->assertEquals(1, $otherProvider->notifications()->count());
        $this->assertEquals(
            'new_emergency_fuel_order',
            $otherProvider->notifications()->first()->type
        );
    }

    public function test_cancelling_provider_is_excluded_from_reannouncement(): void
    {
        $this->useSpyBroadcaster();

        $cancellingProvider = $this->makeApprovedProvider(33.5460, 36.3249);
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeAcceptedOrder($customer, $vehicle, $cancellingProvider, 33.5460, 36.3249, 'دمشق');
        Sanctum::actingAs($cancellingProvider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'عطل في السيارة',
        ])->assertOk();

        $reannounce = array_filter(
            self::$broadcastCaptures,
            fn ($c) => $c['event'] === 'new-emergency-fuel-order'
        );

        $this->assertCount(0, $reannounce);
        $this->assertEquals(0, $cancellingProvider->notifications()->count());
    }

    public function test_no_exception_when_no_other_provider_is_eligible(): void
    {
        // نفس سيناريو غياب مزود بديل الذي كان سابقاً يسبب استدعاء broadcastOn() بمزود null —
        // يجب ألا يحدث أي خطأ وأن يبقى الطلب معاد فتحه بنجاح.
        $cancellingProvider = $this->makeApprovedProvider(33.5460, 36.3249);
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeAcceptedOrder($customer, $vehicle, $cancellingProvider, 33.5460, 36.3249, 'دمشق');
        Sanctum::actingAs($cancellingProvider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'عطل في السيارة',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->fuel_provider_id);
    }

    public function test_customer_notification_remains_intact_after_reannounce_fix(): void
    {
        $cancellingProvider = $this->makeApprovedProvider(33.5460, 36.3249);
        $otherProvider = $this->makeApprovedProvider(33.5470, 36.3260);
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $order = $this->makeAcceptedOrder($customer, $vehicle, $cancellingProvider, 33.5460, 36.3249, 'دمشق');
        Sanctum::actingAs($cancellingProvider);

        $this->postJson("/api/fuel_provider/orders/{$order->id}/cancel", [
            'cancellation_reason' => 'عطل في السيارة',
        ])->assertOk();

        $this->assertEquals(1, $customer->notifications()->count());
        $notification = $customer->notifications()->first();
        $this->assertEquals('fuel_order_reopened_after_provider_cancel', $notification->type);
        $this->assertEquals('pending', $notification->data['data']['status']);
    }
}
