<?php

// للتذكير: هذا الملف يختبر الإشعارات الدائمة لتحولات عروض أسعار الصيانة (تقديم، قبول، رفض).

namespace Tests\Feature;

use App\Models\MaintenanceRequest;
use App\Models\Quotation;
use App\Models\ServiceJob;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class MaintenanceNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeCustomerWithVehicle(): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Toyota', 'model' => 'Corolla',
            'year' => 2020, 'plate_number' => 'MN-' . uniqid(),
        ]);

        return [$customer, $vehicle];
    }

    private function makeApprovedTechnician(): User
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);

        return $techUser;
    }

    private function makeRequest($customer, $vehicle, string $status = 'pending'): MaintenanceRequest
    {
        return MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'صوت غريب', 'priority' => 'high', 'status' => $status,
        ]);
    }

    private function makeQuotation(MaintenanceRequest $request, User $tech, string $status = 'pending', array $overrides = []): Quotation
    {
        return Quotation::create(array_merge([
            'maintenance_request_id' => $request->id,
            'technician_id' => $tech->id,
            'price' => 100,
            'estimated_days' => 2,
            'status' => $status,
        ], $overrides));
    }

    public function test_submit_quotation_notifies_customer_only(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'pending');
        $tech = $this->makeApprovedTechnician();
        $unrelated = $this->makeUser();
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/requests/{$request->id}/quotation", [
            'price' => 150, 'estimated_days' => 3,
        ])->assertCreated()->assertJson(['success' => true]);

        $this->assertEquals('quoted', $request->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $tech->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $quotation = Quotation::where('maintenance_request_id', $request->id)->first();
        $notification = $customer->notifications()->first();
        $this->assertEquals('maintenance_quotation_received', $notification->type);
        $this->assertEquals('تم استلام عرض سعر جديد', $notification->data['title']);
        $this->assertEquals('تلقيت عرض سعر جديد على طلب الصيانة الخاص بك', $notification->data['body']);
        $this->assertEquals('maintenance_request', $notification->data['data']['entity_type']);
        $this->assertEquals($request->id, $notification->data['data']['entity_id']);
        $this->assertEquals('open_details', $notification->data['data']['action']);
        $this->assertEquals('quoted', $notification->data['data']['status']);
        $this->assertEquals($quotation->id, $notification->data['data']['quotation_id']);
        $this->assertEquals($tech->id, $notification->data['data']['technician_id']);
        $this->assertEquals($quotation->price, $notification->data['data']['price']);
    }

    public function test_duplicate_quotation_attempt_creates_no_second_notification(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'pending');
        $tech = $this->makeApprovedTechnician();
        $this->makeQuotation($request, $tech, 'pending');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/requests/{$request->id}/quotation", [
            'price' => 150, 'estimated_days' => 3,
        ])->assertStatus(400)->assertJson(['success' => false]);

        $this->assertEquals(0, $customer->notifications()->count());
    }

    public function test_quick_quotation_acceptance_notifies_selected_technician_only(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $tech = $this->makeApprovedTechnician();
        $quotation = $this->makeQuotation($request, $tech);
        $unrelated = $this->makeUser();
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$quotation->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $tech->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $tech->notifications()->first();
        $this->assertEquals('maintenance_quotation_accepted', $notification->type);
        $this->assertEquals([
            'entity_type' => 'maintenance_request',
            'entity_id' => $request->id,
            'action' => 'open_details',
            'status' => 'quotation_accepted',
            'quotation_id' => $quotation->id,
            'service_job_id' => null,
            'scheduled_date' => null,
        ], $notification->data['data']);
    }

    public function test_scheduled_quotation_acceptance_notifies_selected_technician_with_job_data(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $tech = $this->makeApprovedTechnician();
        $quotation = $this->makeQuotation($request, $tech);
        Sanctum::actingAs($customer);

        $response = $this->postJson("/api/maintenance-requests/{$request->id}/accept-quotation/{$quotation->id}", [
            'scheduled_date' => now()->addDays(2)->toDateString(),
        ])->assertOk()->assertJson(['success' => true]);

        $serviceJob = $response->json('data.service_job');
        $this->assertNotNull($serviceJob);

        $this->assertEquals(1, $tech->notifications()->count());
        $notification = $tech->notifications()->first();
        $this->assertEquals('maintenance_quotation_accepted', $notification->type);
        $this->assertEquals($serviceJob['id'], $notification->data['data']['service_job_id']);
        $this->assertEquals(
            now()->addDays(2)->toDateString(),
            $notification->data['data']['scheduled_date']
        );
    }

    public function test_explicit_quotation_rejection_notifies_technician(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $tech = $this->makeApprovedTechnician();
        $quotation = $this->makeQuotation($request, $tech);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/quotations/{$quotation->id}/reject", [
            'reason' => 'سعر مرتفع',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(1, $tech->notifications()->count());
        $notification = $tech->notifications()->first();
        $this->assertEquals('maintenance_quotation_rejected', $notification->type);
        $this->assertEquals('تم رفض عرض السعر الذي قدمته', $notification->data['body']);
        $this->assertEquals([
            'entity_type' => 'maintenance_request',
            'entity_id' => $request->id,
            'action' => 'open_details',
            'status' => 'rejected',
            'quotation_id' => $quotation->id,
            'rejection_source' => 'explicit',
            'reason' => 'سعر مرتفع',
        ], $notification->data['data']);
    }

    public function test_quick_acceptance_notifies_losing_technicians_without_duplicates(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $winner = $this->makeApprovedTechnician();
        $loser = $this->makeApprovedTechnician();
        $unrelatedTech = $this->makeApprovedTechnician();
        $winnerQuotation = $this->makeQuotation($request, $winner);
        $loserQuotation = $this->makeQuotation($request, $loser);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$winnerQuotation->id}")
            ->assertOk();

        $this->assertEquals(1, $winner->notifications()->count());
        $this->assertEquals(1, $loser->notifications()->count());
        $this->assertEquals(0, $unrelatedTech->notifications()->count());

        $loserNotification = $loser->notifications()->first();
        $this->assertEquals('maintenance_quotation_rejected', $loserNotification->type);
        $this->assertEquals('another_quotation_accepted', $loserNotification->data['data']['rejection_source']);
        $this->assertEquals($loserQuotation->id, $loserNotification->data['data']['quotation_id']);
    }

    public function test_scheduled_acceptance_notifies_losing_technicians_without_duplicates(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $winner = $this->makeApprovedTechnician();
        $loser = $this->makeApprovedTechnician();
        $unrelatedTech = $this->makeApprovedTechnician();
        $winnerQuotation = $this->makeQuotation($request, $winner);
        $loserQuotation = $this->makeQuotation($request, $loser);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quotation/{$winnerQuotation->id}", [])
            ->assertOk();

        $this->assertEquals(1, $winner->notifications()->count());
        $this->assertEquals(1, $loser->notifications()->count());
        $this->assertEquals(0, $unrelatedTech->notifications()->count());

        $loserNotification = $loser->notifications()->first();
        $this->assertEquals('another_quotation_accepted', $loserNotification->data['data']['rejection_source']);
        $this->assertEquals($loserQuotation->id, $loserNotification->data['data']['quotation_id']);
    }

    public function test_submit_quotation_succeeds_when_notification_broadcast_fails(): void
    {
        // MaintenanceRequestService/TechnicianMaintenanceService لا يحتويان أي بث تشغيلي آخر،
        // لذا يمكن لهذا الاختبار عزل فشل NotificationCreated بدقة دون أي غموض.
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'pending');
        $tech = $this->makeApprovedTechnician();

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/requests/{$request->id}/quotation", [
            'price' => 150, 'estimated_days' => 3,
        ])->assertCreated()->assertJson(['success' => true]);

        $this->assertEquals('quoted', $request->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_second_quick_acceptance_attempt_for_another_quotation_is_rejected(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $winner = $this->makeApprovedTechnician();
        $loser = $this->makeApprovedTechnician();
        $winnerQuotation = $this->makeQuotation($request, $winner);
        $loserQuotation = $this->makeQuotation($request, $loser);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$winnerQuotation->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        // محاولة ثانية على عرض آخر بعد أن أصبح الطلب quotation_accepted بالفعل
        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$loserQuotation->id}")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, Quotation::where('maintenance_request_id', $request->id)->where('status', 'accepted')->count());
        $this->assertEquals('accepted', $winnerQuotation->fresh()->status);
        $this->assertEquals('rejected', $loserQuotation->fresh()->status);
        $this->assertEquals(0, ServiceJob::where('maintenance_request_id', $request->id)->count());
        $this->assertEquals(1, $winner->notifications()->count());
        $this->assertEquals(1, $loser->notifications()->count());
    }

    public function test_second_acceptance_attempt_after_scheduled_acceptance_is_rejected(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $winner = $this->makeApprovedTechnician();
        $loser = $this->makeApprovedTechnician();
        $winnerQuotation = $this->makeQuotation($request, $winner);
        $loserQuotation = $this->makeQuotation($request, $loser);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quotation/{$winnerQuotation->id}", [])
            ->assertOk();

        // محاولة ثانية (مجدولة) على عرض آخر بعد اكتمال القبول الأول
        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quotation/{$loserQuotation->id}", [])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, Quotation::where('maintenance_request_id', $request->id)->where('status', 'accepted')->count());
        $this->assertEquals(1, ServiceJob::where('maintenance_request_id', $request->id)->count());
        $this->assertEquals(1, $winner->notifications()->count());
        $this->assertEquals(1, $loser->notifications()->count());
    }

    public function test_quick_acceptance_then_scheduled_retry_creates_no_service_job(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $winner = $this->makeApprovedTechnician();
        $loser = $this->makeApprovedTechnician();
        $winnerQuotation = $this->makeQuotation($request, $winner);
        $loserQuotation = $this->makeQuotation($request, $loser);
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$winnerQuotation->id}")
            ->assertOk();

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quotation/{$loserQuotation->id}", [])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals(0, ServiceJob::where('maintenance_request_id', $request->id)->count());
        $this->assertEquals(1, $winner->notifications()->count());
        $this->assertEquals(1, $loser->notifications()->count());
    }

    public function test_scheduled_acceptance_then_quick_retry_does_not_alter_service_job(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $winner = $this->makeApprovedTechnician();
        $loser = $this->makeApprovedTechnician();
        $winnerQuotation = $this->makeQuotation($request, $winner);
        $loserQuotation = $this->makeQuotation($request, $loser);
        Sanctum::actingAs($customer);

        $response = $this->postJson("/api/maintenance-requests/{$request->id}/accept-quotation/{$winnerQuotation->id}", [])
            ->assertOk();
        $originalJobId = $response->json('data.service_job.id');

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$loserQuotation->id}")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, ServiceJob::where('maintenance_request_id', $request->id)->count());
        $this->assertEquals($originalJobId, ServiceJob::where('maintenance_request_id', $request->id)->first()->id);
        $this->assertEquals(1, $winner->notifications()->count());
        $this->assertEquals(1, $loser->notifications()->count());
    }

    public function test_quick_acceptance_of_already_rejected_quotation_is_rejected(): void
    {
        [$customer, $vehicle] = $this->makeCustomerWithVehicle();
        $request = $this->makeRequest($customer, $vehicle, 'quoted');
        $rejectedTech = $this->makeApprovedTechnician();
        $pendingTech = $this->makeApprovedTechnician();
        $rejectedQuotation = $this->makeQuotation($request, $rejectedTech, 'rejected');
        $pendingQuotation = $this->makeQuotation($request, $pendingTech, 'pending');
        Sanctum::actingAs($customer);

        $this->postJson("/api/maintenance-requests/{$request->id}/accept-quick/{$rejectedQuotation->id}")
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals('rejected', $rejectedQuotation->fresh()->status);
        $this->assertEquals('pending', $pendingQuotation->fresh()->status);
        $this->assertEquals(0, Quotation::where('maintenance_request_id', $request->id)->where('status', 'accepted')->count());
        $this->assertEquals(0, ServiceJob::where('maintenance_request_id', $request->id)->count());
        $this->assertEquals('quoted', $request->fresh()->status);
        $this->assertEquals(0, $rejectedTech->notifications()->count());
        $this->assertEquals(0, $pendingTech->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());
    }
}
