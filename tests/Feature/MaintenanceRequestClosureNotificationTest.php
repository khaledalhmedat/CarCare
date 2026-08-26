<?php

// للتذكير: هذا الملف يختبر إشعارات إنشاء طلب الصيانة (للفنيين المؤهلين) وإلغاء الطلب من العميل (للفنيين أصحاب عروض مفتوحة).

namespace Tests\Feature;

use App\Models\MaintenanceRequest;
use App\Models\Quotation;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class MaintenanceRequestClosureNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeCustomerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'MQ-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function makeTechnician(string $status = 'approved'): User
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => $status,
        ]);

        return $techUser;
    }

    private function createRequestPayload(): array
    {
        return [
            'description' => 'صوت غريب في المحرك عند التشغيل',
            'priority' => 'high',
        ];
    }

    // -------- Task 1: maintenance request created notifies eligible technicians --------

    public function test_request_creation_notifies_approved_technicians(): void
    {
        $approvedTech = $this->makeTechnician('approved');
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        Sanctum::actingAs($customer);

        $this->postJson('/api/maintenance-requests', array_merge($this->createRequestPayload(), [
            'vehicle_id' => $vehicle->id,
        ]))->assertCreated()->assertJson(['success' => true]);

        $this->assertEquals(1, $approvedTech->notifications()->count());
        $notification = $approvedTech->notifications()->first();
        $this->assertEquals('new_maintenance_request', $notification->type);
        $this->assertEquals(MaintenanceRequest::first()->id, $notification->data['data']['entity_id']);
    }

    public function test_request_creation_does_not_notify_non_eligible_technicians_or_customer(): void
    {
        $approvedTech = $this->makeTechnician('approved');
        $pendingTech = $this->makeTechnician('pending');
        $rejectedTech = $this->makeTechnician('rejected');
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        Sanctum::actingAs($customer);

        $this->postJson('/api/maintenance-requests', array_merge($this->createRequestPayload(), [
            'vehicle_id' => $vehicle->id,
        ]))->assertCreated();

        $this->assertEquals(1, $approvedTech->notifications()->count());
        $this->assertEquals(0, $pendingTech->notifications()->count());
        $this->assertEquals(0, $rejectedTech->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
    }

    public function test_request_creation_succeeds_even_when_notification_broadcast_fails(): void
    {
        $this->makeTechnician('approved');
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($customer);

        $this->postJson('/api/maintenance-requests', array_merge($this->createRequestPayload(), [
            'vehicle_id' => $vehicle->id,
        ]))->assertCreated()->assertJson(['success' => true]);

        $this->assertEquals(1, MaintenanceRequest::count());
    }

    // -------- Task 2: cancellation by customer notifies technicians with an open quotation --------

    public function test_cancellation_notifies_technician_with_pending_quotation(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'quoted',
        ]);
        $tech = $this->makeTechnician();
        Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $tech->id,
            'price' => 100, 'estimated_days' => 2, 'status' => 'pending',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'تراجعت عن الطلب',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(1, $tech->notifications()->count());
        $notification = $tech->notifications()->first();
        $this->assertEquals('maintenance_request_cancelled', $notification->type);
        $this->assertEquals([
            'entity_type' => 'maintenance_request',
            'entity_id' => $request->id,
            'action' => 'open_details',
            'status' => 'cancelled',
            'reason' => 'تراجعت عن الطلب',
        ], $notification->data['data']);
    }

    public function test_cancellation_does_not_notify_unrelated_technician(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'quoted',
        ]);
        $tech = $this->makeTechnician();
        Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $tech->id,
            'price' => 100, 'estimated_days' => 2, 'status' => 'pending',
        ]);
        $unrelatedTech = $this->makeTechnician();
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'تراجعت عن الطلب',
        ])->assertOk();

        $this->assertEquals(1, $tech->notifications()->count());
        $this->assertEquals(0, $unrelatedTech->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
    }

    public function test_cancellation_does_not_notify_technician_with_rejected_quotation(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'pending',
        ]);
        $rejectedTech = $this->makeTechnician();
        Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $rejectedTech->id,
            'price' => 100, 'estimated_days' => 2, 'status' => 'rejected',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'تراجعت عن الطلب',
        ])->assertOk();

        $this->assertEquals(0, $rejectedTech->notifications()->count());
    }

    public function test_cancellation_still_succeeds_when_notification_broadcast_fails(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'quoted',
        ]);
        $tech = $this->makeTechnician();
        Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $tech->id,
            'price' => 100, 'estimated_days' => 2, 'status' => 'pending',
        ]);

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'تراجعت عن الطلب',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $request->fresh()->status);
    }

    public function test_technician_appearing_via_multiple_pending_quotation_rows_is_notified_only_once(): void
    {
        // لو ظهر نفس الفني أكثر من مرة ضمن العروض المفتوحة (خارج مسار submitQuotation الطبيعي)،
        // يجب ألا يتلقى أكثر من إشعار واحد لنفس عملية الإلغاء.
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'quoted',
        ]);
        $tech = $this->makeTechnician();
        Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $tech->id,
            'price' => 100, 'estimated_days' => 2, 'status' => 'pending',
        ]);
        Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $tech->id,
            'price' => 120, 'estimated_days' => 3, 'status' => 'pending',
        ]);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/cancel", [
            'cancellation_reason' => 'تراجعت عن الطلب',
        ])->assertOk();

        $this->assertEquals(1, $tech->notifications()->count());
    }
}
