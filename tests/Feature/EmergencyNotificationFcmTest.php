<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\FuelOrder;
use App\Models\FuelProvider;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class EmergencyNotificationFcmTest extends TestCase
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
                    EmergencyNotificationFcmTest::$broadcastCaptures[] = [
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

    private function makeApprovedTechnician(float $lat = 33.54, float $lng = 36.32, string $city = 'دمشق'): User
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => $city, 'status' => 'approved',
            'latitude' => $lat, 'longitude' => $lng,
        ]);

        return $techUser;
    }

    private function makeApprovedFuelProvider(float $lat = 33.54, float $lng = 36.32, string $city = 'دمشق'): User
    {
        $providerUser = $this->makeUser();
        FuelProvider::create([
            'user_id' => $providerUser->id, 'company_name' => 'FuelCo', 'phone' => '05', 'city' => $city,
            'address' => 'x', 'status' => 'approved', 'is_available' => true,
            'latitude' => $lat, 'longitude' => $lng,
        ]);

        return $providerUser;
    }

    private function customerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'EM-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function createSos(): array
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/sos', [
            'vehicle_id' => $vehicle->id,
            'lat' => 33.54,
            'lng' => 36.32,
            'city' => 'دمشق',
        ])->assertCreated();

        $sos = SosRequest::findOrFail($response->json('data.id'));

        return [$customer, $sos];
    }

    private function createEmergencyFuelOrder(): array
    {
        [$customer, $vehicle] = $this->customerWithVehicle();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/customer/fuel_orders/emergency', [
            'vehicle_id' => $vehicle->id,
            'fuel_type' => '95',
            'amount' => 20,
            'delivery_latitude' => 33.54,
            'delivery_longitude' => 36.32,
            'city' => 'دمشق',
        ])->assertCreated();

        $order = FuelOrder::findOrFail($response->json('data.id'));

        return [$customer, $order];
    }

    public function test_sos_creation_still_broadcasts_new_sos_request_to_technician(): void
    {
        $this->useSpyBroadcaster();
        $tech = $this->makeApprovedTechnician();

        [, $sos] = $this->createSos();

        $specialized = array_filter(self::$broadcastCaptures, fn ($c) => $c['event'] === 'new-sos-request');
        $this->assertCount(1, $specialized);
        $capture = array_values($specialized)[0];
        $this->assertSame(["technician.{$tech->id}"], $capture['channels']);
    }

    public function test_sos_realtime_payload_shape_unchanged(): void
    {
        $this->useSpyBroadcaster();
        $this->makeApprovedTechnician();

        [, $sos] = $this->createSos();

        $specialized = array_values(array_filter(self::$broadcastCaptures, fn ($c) => $c['event'] === 'new-sos-request'));
        $payload = $specialized[0]['payload'];

        $this->assertSame($sos->id, $payload['id']);
        $this->assertArrayHasKey('lat', $payload);
        $this->assertArrayHasKey('lng', $payload);
        $this->assertArrayHasKey('vehicle', $payload);
    }

    public function test_sos_recipient_technician_receives_exactly_one_db_notification(): void
    {
        $tech = $this->makeApprovedTechnician();

        $this->createSos();

        $this->assertEquals(1, $tech->notifications()->count());
    }

    public function test_sos_notification_dispatches_exactly_one_parent_fcm_job(): void
    {
        Bus::fake();
        $tech = $this->makeApprovedTechnician();

        $this->createSos();

        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
        Bus::assertDispatched(SendFcmNotification::class, fn ($job) => $job->userId === $tech->id);
    }

    public function test_sos_notification_type_is_expected_stable_type(): void
    {
        $tech = $this->makeApprovedTechnician();

        $this->createSos();

        $this->assertEquals('new_sos_request', $tech->notifications()->first()->type);
    }

    public function test_sos_notification_data_contains_correct_sos_identifier(): void
    {
        $tech = $this->makeApprovedTechnician();

        [, $sos] = $this->createSos();

        $data = $tech->notifications()->first()->data['data'];
        $this->assertEquals('sos_request', $data['entity_type']);
        $this->assertEquals($sos->id, $data['entity_id']);
    }

    public function test_non_recipient_technician_receives_no_sos_notification(): void
    {
        $farTech = $this->makeApprovedTechnician(24.71, 46.67, 'الرياض');

        $this->createSos();

        $this->assertEquals(0, $farTech->notifications()->count());
    }

    public function test_same_technician_not_notified_twice_for_one_sos(): void
    {
        $tech = $this->makeApprovedTechnician();

        $this->createSos();

        $this->assertEquals(1, $tech->notifications()->count());
    }

    public function test_sos_multiple_recipient_technicians_each_notified_once(): void
    {
        $this->useSpyBroadcaster();
        $techA = $this->makeApprovedTechnician(33.54, 36.32);
        $techB = $this->makeApprovedTechnician(33.55, 36.33);
        $techC = $this->makeApprovedTechnician(33.53, 36.31);

        $this->createSos();

        $specialized = array_filter(self::$broadcastCaptures, fn ($c) => $c['event'] === 'new-sos-request');
        $this->assertCount(3, $specialized);

        foreach ([$techA, $techB, $techC] as $tech) {
            $this->assertEquals(1, $tech->notifications()->count());
        }

        Bus::fake();
        $this->createSos();

        Bus::assertDispatchedTimes(SendFcmNotification::class, 3);
        foreach ([$techA, $techB, $techC] as $tech) {
            Bus::assertDispatched(SendFcmNotification::class, fn ($job) => $job->userId === $tech->id);
        }
    }

    public function test_emergency_fuel_still_broadcasts_new_emergency_fuel_order_to_provider(): void
    {
        $this->useSpyBroadcaster();
        $provider = $this->makeApprovedFuelProvider();

        $this->createEmergencyFuelOrder();

        $specialized = array_filter(self::$broadcastCaptures, fn ($c) => $c['event'] === 'new-emergency-fuel-order');
        $this->assertCount(1, $specialized);
        $capture = array_values($specialized)[0];
        $this->assertSame(["fuel-provider.{$provider->id}"], $capture['channels']);
    }

    public function test_emergency_fuel_realtime_payload_shape_unchanged(): void
    {
        $this->useSpyBroadcaster();
        $this->makeApprovedFuelProvider();

        [, $order] = $this->createEmergencyFuelOrder();

        $specialized = array_values(array_filter(self::$broadcastCaptures, fn ($c) => $c['event'] === 'new-emergency-fuel-order'));
        $payload = $specialized[0]['payload'];

        $this->assertSame($order->id, $payload['order_id']);
        $this->assertArrayHasKey('fuel_type', $payload);
        $this->assertArrayHasKey('vehicle', $payload);
    }

    public function test_emergency_fuel_provider_receives_exactly_one_db_notification(): void
    {
        $provider = $this->makeApprovedFuelProvider();

        $this->createEmergencyFuelOrder();

        $this->assertEquals(1, $provider->notifications()->count());
    }

    public function test_emergency_fuel_notification_dispatches_exactly_one_parent_fcm_job(): void
    {
        Bus::fake();
        $provider = $this->makeApprovedFuelProvider();

        $this->createEmergencyFuelOrder();

        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
        Bus::assertDispatched(SendFcmNotification::class, fn ($job) => $job->userId === $provider->id);
    }

    public function test_emergency_fuel_notification_type_is_expected_stable_type(): void
    {
        $provider = $this->makeApprovedFuelProvider();

        $this->createEmergencyFuelOrder();

        $this->assertEquals('new_emergency_fuel_order', $provider->notifications()->first()->type);
    }

    public function test_emergency_fuel_notification_data_contains_correct_order_identifier(): void
    {
        $provider = $this->makeApprovedFuelProvider();

        [, $order] = $this->createEmergencyFuelOrder();

        $data = $provider->notifications()->first()->data['data'];
        $this->assertEquals('emergency_fuel_order', $data['entity_type']);
        $this->assertEquals($order->id, $data['entity_id']);
    }

    public function test_non_recipient_provider_receives_no_emergency_fuel_notification(): void
    {
        $farProvider = $this->makeApprovedFuelProvider(24.71, 46.67, 'الرياض');

        $this->createEmergencyFuelOrder();

        $this->assertEquals(0, $farProvider->notifications()->count());
    }

    public function test_same_provider_not_notified_twice_for_one_order(): void
    {
        $provider = $this->makeApprovedFuelProvider();

        $this->createEmergencyFuelOrder();

        $this->assertEquals(1, $provider->notifications()->count());
    }

    public function test_emergency_fuel_multiple_recipient_providers_each_notified_once(): void
    {
        $this->useSpyBroadcaster();
        $providerA = $this->makeApprovedFuelProvider(33.54, 36.32);
        $providerB = $this->makeApprovedFuelProvider(33.55, 36.33);
        $providerC = $this->makeApprovedFuelProvider(33.53, 36.31);

        $this->createEmergencyFuelOrder();

        $specialized = array_filter(self::$broadcastCaptures, fn ($c) => $c['event'] === 'new-emergency-fuel-order');
        $this->assertCount(3, $specialized);

        foreach ([$providerA, $providerB, $providerC] as $provider) {
            $this->assertEquals(1, $provider->notifications()->count());
        }

        Bus::fake();
        $this->createEmergencyFuelOrder();

        Bus::assertDispatchedTimes(SendFcmNotification::class, 3);
        foreach ([$providerA, $providerB, $providerC] as $provider) {
            Bus::assertDispatched(SendFcmNotification::class, fn ($job) => $job->userId === $provider->id);
        }
    }
}
