<?php

// للتذكير: هذا الملف يختبر بث الحدث اللحظي الخاص بإلغاء العميل لطلب الطوارئ (SosCancelledByCustomer).

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosCustomerCancelBroadcastTest extends TestCase
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
                    SosCustomerCancelBroadcastTest::$broadcastCaptures[] = [
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

    private function makeTechnician()
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);

        return $techUser;
    }

    private function makeSos($techUser, string $status): SosRequest
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'SB-' . uniqid(),
        ]);

        return SosRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'technician_id' => $techUser?->id, 'lat' => 33.54, 'lng' => 36.32, 'status' => $status,
        ]);
    }

    public function test_customer_cancellation_triggers_expected_sos_realtime_broadcast(): void
    {
        $this->useSpyBroadcaster();
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'accepted');
        $customer = $sos->user;
        Sanctum::actingAs($customer);

        $this->postJson("/api/sos/{$sos->id}/cancel", ['cancellation_reason' => 'لم أعد بحاجة للمساعدة'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $captures = array_values(array_filter(
            self::$broadcastCaptures,
            fn ($c) => $c['event'] === 'sos-cancelled-by-customer'
        ));

        $this->assertCount(1, $captures);
        $this->assertSame(["sos.{$sos->id}", "technician.{$tech->id}"], $captures[0]['channels']);
        $this->assertSame($sos->id, $captures[0]['payload']['sos_request_id']);
        $this->assertSame('لم أعد بحاجة للمساعدة', $captures[0]['payload']['reason']);
    }

    public function test_no_realtime_broadcast_when_no_technician_assigned(): void
    {
        $this->useSpyBroadcaster();
        $sos = $this->makeSos(null, 'open');
        $customer = $sos->user;
        Sanctum::actingAs($customer);

        $this->postJson("/api/sos/{$sos->id}/cancel", ['cancellation_reason' => 'لم أعد بحاجة للمساعدة'])
            ->assertOk();

        $captures = array_filter(
            self::$broadcastCaptures,
            fn ($c) => $c['event'] === 'sos-cancelled-by-customer'
        );

        $this->assertCount(0, $captures);
    }

    public function test_cancellation_still_succeeds_and_persists_notification_when_broadcast_fails(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'accepted');
        $customer = $sos->user;

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($customer);

        $this->postJson("/api/sos/{$sos->id}/cancel", ['cancellation_reason' => 'لم أعد بحاجة للمساعدة'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $sos->fresh()->status);
        $this->assertEquals(1, $tech->notifications()->count());
    }
}
