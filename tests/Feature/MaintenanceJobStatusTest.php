<?php

// للتذكير: هذا الملف يختبر انتقالات حالة مهمة الصيانة (idempotency) وإشعارات بدء/إنجاز الصيانة.

namespace Tests\Feature;

use App\Models\MaintenanceRecord;
use App\Models\MaintenanceRequest;
use App\Models\Quotation;
use App\Models\ServiceJob;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class MaintenanceJobStatusTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeApprovedTechnician(): User
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);

        return $techUser;
    }

    private function makeJob(User $tech, string $jobStatus = 'assigned'): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'MJ-' . uniqid(),
        ]);
        $request = MaintenanceRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'description' => 'test', 'priority' => 'high', 'status' => 'quotation_accepted',
        ]);
        $quotation = Quotation::create([
            'maintenance_request_id' => $request->id, 'technician_id' => $tech->id,
            'price' => 100, 'estimated_days' => 2, 'status' => 'accepted',
        ]);
        $job = ServiceJob::create([
            'maintenance_request_id' => $request->id, 'quotation_id' => $quotation->id,
            'technician_id' => $tech->id, 'status' => $jobStatus,
            'scheduled_date' => now()->addDay(),
        ]);

        return [$customer, $request, $job];
    }

    public function test_assigned_to_in_progress_notifies_customer_only(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'assigned');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('in_progress', $job->fresh()->status);
        $this->assertEquals('in_progress', $request->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $tech->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('maintenance_job_in_progress', $notification->type);
        $this->assertEquals([
            'entity_type' => 'maintenance_request',
            'entity_id' => $request->id,
            'action' => 'open_details',
            'status' => 'in_progress',
            'service_job_id' => $job->id,
            'technician_id' => $tech->id,
        ], $notification->data['data']);
    }

    public function test_valid_completion_creates_one_record_and_one_notification(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'in_progress');
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", [
            'status' => 'completed', 'completion_notes' => 'تم بنجاح',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('completed', $job->fresh()->status);
        $this->assertEquals(1, MaintenanceRecord::where('service_job_id', $job->id)->count());
        $this->assertEquals(1, $customer->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('maintenance_job_completed', $notification->type);
        $this->assertEquals([
            'entity_type' => 'maintenance_request',
            'entity_id' => $request->id,
            'action' => 'open_details',
            'status' => 'completed',
            'service_job_id' => $job->id,
            'technician_id' => $tech->id,
        ], $notification->data['data']);
    }

    public function test_repeated_completed_creates_no_duplicate_record_or_notification(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'in_progress');
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", [
            'status' => 'completed', 'completion_notes' => 'تم بنجاح',
        ])->assertOk();

        $this->patchJson("/api/technician/jobs/{$job->id}/status", [
            'status' => 'completed', 'completion_notes' => 'محاولة ثانية',
        ])->assertStatus(400)->assertJson(['success' => false]);

        $this->assertEquals(1, MaintenanceRecord::where('service_job_id', $job->id)->count());
        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_repeated_in_progress_creates_no_duplicate_notification(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'assigned');
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        $this->patchJson("/api/technician/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_completed_to_in_progress_rejected_with_no_side_effects(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'completed');
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals('completed', $job->fresh()->status);
        $this->assertEquals(0, MaintenanceRecord::where('service_job_id', $job->id)->count());
        $this->assertEquals(0, $customer->notifications()->count());
    }

    public function test_unauthorized_technician_cannot_update_others_job(): void
    {
        $owner = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($owner, 'assigned');
        $other = $this->makeApprovedTechnician();
        Sanctum::actingAs($other);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals('assigned', $job->fresh()->status);
        $this->assertEquals(0, MaintenanceRecord::where('service_job_id', $job->id)->count());
        $this->assertEquals(0, $customer->notifications()->count());
        $this->assertEquals(0, $other->notifications()->count());
    }

    public function test_assigned_to_completed_remains_supported(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'assigned');
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", [
            'status' => 'completed', 'completion_notes' => 'أُنجزت مباشرة',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals('completed', $job->fresh()->status);
        $this->assertEquals(1, MaintenanceRecord::where('service_job_id', $job->id)->count());
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals('maintenance_job_completed', $customer->notifications()->first()->type);
    }

    public function test_transition_succeeds_and_skips_notification_when_customer_soft_deleted(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'assigned');
        $customer->delete();
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('in_progress', $job->fresh()->status);
        $this->assertEquals(0, \Illuminate\Notifications\DatabaseNotification::count());
    }

    public function test_completed_requests_list_survives_soft_deleted_vehicle(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'assigned');
        Sanctum::actingAs($tech);
        $this->patchJson("/api/technician/jobs/{$job->id}/status", [
            'status' => 'completed', 'completion_notes' => 'تم بنجاح',
        ])->assertOk();

        $vehicle = $request->vehicle;
        $vehicle->delete();

        Sanctum::actingAs($customer);

        $this->getJson('/api/maintenance-requests')
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_record.vehicle', null);

        $this->getJson('/api/maintenance-requests/filter/completed')
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_record.vehicle', null);
    }

    public function test_completed_requests_list_survives_soft_deleted_technician(): void
    {
        $tech = $this->makeApprovedTechnician();
        [$customer, $request, $job] = $this->makeJob($tech, 'assigned');
        Sanctum::actingAs($tech);
        $this->patchJson("/api/technician/jobs/{$job->id}/status", [
            'status' => 'completed', 'completion_notes' => 'تم بنجاح',
        ])->assertOk();

        $tech->delete();

        Sanctum::actingAs($customer);

        $this->getJson('/api/maintenance-requests')
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_record.technician', null);

        $this->getJson('/api/maintenance-requests/filter/completed')
            ->assertOk()
            ->assertJsonPath('data.0.maintenance_record.technician', null);
    }
}
