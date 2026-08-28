<?php

// للتذكير: هذا الملف يختبر Adaptive Progressive Radius Expansion لطلبات SOS (Available/Notification/Accept/Recovery).

namespace Tests\Feature;

use App\Jobs\ExpandDispatchRadius;
use App\Jobs\MaxRadiusRecheckJob;
use App\Models\DispatchNotificationRecipient;
use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosDispatchRadiusExpansionTest extends TestCase
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

    private function makeTechnicianAtDistance(float $km, array $overrides = []): Technician
    {
        $u = $this->makeUserWithRole('technician');

        return Technician::create(array_merge([
            'user_id' => $u->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved', 'is_available' => true,
            'latitude' => self::BASE_LAT + $km * self::KM_TO_LAT_DEGREES,
            'longitude' => self::BASE_LNG,
        ], $overrides));
    }

    private function ensureCustomer(): void
    {
        if (isset($this->customerId)) {
            return;
        }

        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'SD-' . uniqid(),
        ]);
        $this->customerId = $customer->id;
        $this->customerVehicleId = $vehicle->id;
    }

    private function createRequestViaApi(array $overrides = []): SosRequest
    {
        $this->ensureCustomer();
        $customer = User::find($this->customerId);
        Sanctum::actingAs($customer);

        $this->postJson('/api/sos', array_merge([
            'vehicle_id' => $this->customerVehicleId,
            'lat' => self::BASE_LAT,
            'lng' => self::BASE_LNG,
            'city' => 'دمشق',
        ], $overrides))->assertCreated();

        return SosRequest::latest('id')->first();
    }

    private function availableIdsFor(Technician $technician): array
    {
        Sanctum::actingAs(User::find($technician->user_id));
        $res = $this->getJson('/api/technician/sos/available', [
            'lat' => $technician->latitude,
            'lng' => $technician->longitude,
        ])->assertOk();

        return array_map('intval', array_column($res->json('data'), 'id'));
    }

    private function notifiedCount(SosRequest $sos, Technician $technician): int
    {
        return DispatchNotificationRecipient::where('service_type', 'sos')
            ->where('request_id', $sos->id)
            ->where('recipient_type', 'technician')
            ->where('recipient_id', $technician->id)
            ->count();
    }

    private function accept(Technician $technician, SosRequest $sos)
    {
        Sanctum::actingAs(User::find($technician->user_id));

        return $this->postJson("/api/technician/sos/requests/{$sos->id}/accept", []);
    }

    public function test_initial_radius_only_near_technician_visible_notified_acceptable(): void
    {
        $a = $this->makeTechnicianAtDistance(7);
        $b = $this->makeTechnicianAtDistance(16);
        $c = $this->makeTechnicianAtDistance(28, ['city' => 'Daraa']);
        $d = $this->makeTechnicianAtDistance(37);
        $e = $this->makeTechnicianAtDistance(48);
        $f = $this->makeTechnicianAtDistance(65);

        $sos = $this->createRequestViaApi();

        $this->assertEquals(10, $sos->fresh()->current_radius_km);

        $this->assertContains($sos->id, $this->availableIdsFor($a));
        foreach ([$b, $c, $d, $e, $f] as $t) {
            $this->assertNotContains($sos->id, $this->availableIdsFor($t));
        }

        $this->assertEquals(1, $this->notifiedCount($sos, $a));
        foreach ([$b, $c, $d, $e, $f] as $t) {
            $this->assertEquals(0, $this->notifiedCount($sos, $t));
        }

        $this->accept($a, $sos)->assertOk()->assertJson(['success' => true]);
    }

    public function test_progressive_expansion_notifies_only_newly_eligible_each_stage(): void
    {
        $a = $this->makeTechnicianAtDistance(7);
        $b = $this->makeTechnicianAtDistance(16);
        $c = $this->makeTechnicianAtDistance(28);
        $d = $this->makeTechnicianAtDistance(37);
        $e = $this->makeTechnicianAtDistance(48);

        $sos = $this->createRequestViaApi();
        $service = app(SosService::class);

        $this->assertEquals(10, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $a));

        $service->expandDispatchRadius($sos->id, 10);
        $this->assertEquals(20, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $a));
        $this->assertEquals(1, $this->notifiedCount($sos, $b));

        $service->expandDispatchRadius($sos->id, 20);
        $this->assertEquals(30, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $c));

        $service->expandDispatchRadius($sos->id, 30);
        $this->assertEquals(40, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $d));

        $service->expandDispatchRadius($sos->id, 40);
        $this->assertEquals(50, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $e));

        Queue::assertPushed(ExpandDispatchRadius::class);
    }

    public function test_empty_radii_are_skipped_immediately(): void
    {
        $a = $this->makeTechnicianAtDistance(34);
        $b = $this->makeTechnicianAtDistance(38);

        $sos = $this->createRequestViaApi();

        $this->assertEquals(40, $sos->fresh()->current_radius_km);
        $this->assertContains($sos->id, $this->availableIdsFor($a));
        $this->assertContains($sos->id, $this->availableIdsFor($b));
        $this->assertEquals(1, $this->notifiedCount($sos, $a));
        $this->assertEquals(1, $this->notifiedCount($sos, $b));
    }

    public function test_governorate_has_zero_effect_on_dispatch(): void
    {
        $damascus = $this->makeTechnicianAtDistance(8, ['city' => 'Damascus']);
        $daraa = $this->makeTechnicianAtDistance(18, ['city' => 'Daraa']);

        $sos = $this->createRequestViaApi(['city' => 'Daraa']);
        $service = app(SosService::class);

        $this->assertEquals(10, $sos->fresh()->current_radius_km);
        $this->assertContains($sos->id, $this->availableIdsFor($damascus));
        $this->assertNotContains($sos->id, $this->availableIdsFor($daraa));

        $service->expandDispatchRadius($sos->id, 10);
        $this->assertEquals(20, $sos->fresh()->current_radius_km);
        $this->assertContains($sos->id, $this->availableIdsFor($daraa));
    }

    public function test_boundary_distance_is_inclusive(): void
    {
        $inside = $this->makeTechnicianAtDistance(9.9);
        $outside = $this->makeTechnicianAtDistance(10.1);

        $sos = $this->createRequestViaApi();

        $this->assertEquals(10, $sos->fresh()->current_radius_km);
        $this->assertContains($sos->id, $this->availableIdsFor($inside));
        $this->assertNotContains($sos->id, $this->availableIdsFor($outside));
    }

    public function test_direct_api_accept_denied_then_allowed_after_expansion(): void
    {
        $near = $this->makeTechnicianAtDistance(16);
        $far = $this->makeTechnicianAtDistance(35);

        $sos = $this->createRequestViaApi();
        app(SosService::class)->expandDispatchRadius($sos->id, 10);
        $this->assertEquals(20, $sos->fresh()->current_radius_km);

        $this->accept($far, $sos)
            ->assertStatus(422)
            ->assertJson(['success' => false, 'code' => 'OUT_OF_SERVICE_RANGE']);

        app(SosService::class)->expandDispatchRadius($sos->id, 20);
        app(SosService::class)->expandDispatchRadius($sos->id, 30);
        $this->assertEquals(40, $sos->fresh()->current_radius_km);

        $this->accept($far, $sos)->assertOk()->assertJson(['success' => true]);
    }

    public function test_accept_stops_a_stale_expansion_job_from_doing_anything(): void
    {
        $a = $this->makeTechnicianAtDistance(7);
        $sos = $this->createRequestViaApi();

        $this->accept($a, $sos)->assertOk();
        $this->assertEquals('accepted', $sos->fresh()->status);

        app(SosService::class)->expandDispatchRadius($sos->id, 10);

        $this->assertEquals(10, $sos->fresh()->current_radius_km);
        $this->assertEquals('accepted', $sos->fresh()->status);
    }

    public function test_expansion_job_retry_is_idempotent(): void
    {
        $a = $this->makeTechnicianAtDistance(7);
        $b = $this->makeTechnicianAtDistance(16);
        $sos = $this->createRequestViaApi();

        app(SosService::class)->expandDispatchRadius($sos->id, 10);
        $this->assertEquals(20, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $b));

        app(SosService::class)->expandDispatchRadius($sos->id, 10);
        $this->assertEquals(20, $sos->fresh()->current_radius_km);
        $this->assertEquals(1, $this->notifiedCount($sos, $b));
    }

    public function test_two_eligible_technicians_racing_only_one_wins(): void
    {
        $a = $this->makeTechnicianAtDistance(5);
        $b = $this->makeTechnicianAtDistance(6);
        $sos = $this->createRequestViaApi();

        $this->accept($a, $sos)->assertOk();
        $this->accept($b, $sos)->assertStatus(500)->assertJson(['success' => false]);

        $this->assertEquals($a->user_id, $sos->fresh()->technician_id);
    }

    public function test_max_radius_exhaustion_then_recheck_discovers_new_technician(): void
    {
        $sos = $this->createRequestViaApi();

        $this->assertEquals(70, $sos->fresh()->current_radius_km);
        Queue::assertPushed(MaxRadiusRecheckJob::class, 1);

        $late = $this->makeTechnicianAtDistance(12);

        app(SosService::class)->recheckMaxRadius($sos->id);

        $this->assertEquals(70, $sos->fresh()->current_radius_km);
        $this->assertContains($sos->id, $this->availableIdsFor($late));
        $this->assertEquals(1, $this->notifiedCount($sos, $late));
        $this->accept($late, $sos)->assertOk();
    }
}
