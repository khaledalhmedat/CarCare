<?php

// للتذكير: هذا الملف يختبر إلغاء الفني لطلب الطوارئ حسب الحالة والصلاحية.

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosCancelTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([\App\Jobs\ExpandDispatchRadius::class, \App\Jobs\MaxRadiusRecheckJob::class]);
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
            'year' => 2019, 'plate_number' => 'SC-' . uniqid(),
        ]);

        return SosRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'technician_id' => $techUser?->id, 'lat' => 33.54, 'lng' => 36.32, 'status' => $status,
        ]);
    }

    public function test_technician_can_cancel_accepted_request(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'accepted');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('open', $sos->fresh()->status);
    }

    public function test_technician_can_cancel_in_progress_request(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'in_progress');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_technician_cannot_cancel_completed_request(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'completed');
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    public function test_wrong_technician_cannot_cancel_others_request(): void
    {
        $owner = $this->makeTechnician();
        $sos = $this->makeSos($owner, 'accepted');
        $other = $this->makeTechnician();
        Sanctum::actingAs($other);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->assertEquals('accepted', $sos->fresh()->status);
    }

    public function test_customer_cancel_of_assigned_sos_notifies_technician_only(): void
    {
        $tech = $this->makeTechnician();
        $sos = $this->makeSos($tech, 'accepted');
        $customer = $sos->user;
        Sanctum::actingAs($customer);

        $this->postJson("/api/sos/{$sos->id}/cancel", ['cancellation_reason' => 'لم أعد بحاجة للمساعدة'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $sos->fresh()->status);
        $this->assertEquals(1, $tech->notifications()->count());
        $this->assertEquals(0, $customer->notifications()->count());

        $notification = $tech->notifications()->first();
        $this->assertEquals('sos_cancelled_by_customer', $notification->type);
        $this->assertEquals([
            'entity_type' => 'sos_request',
            'entity_id' => $sos->id,
            'action' => 'open_details',
            'status' => 'cancelled',
            'reason' => 'لم أعد بحاجة للمساعدة',
        ], $notification->data['data']);
    }

    public function test_customer_cancel_of_unassigned_open_sos_creates_no_notification(): void
    {
        $sos = $this->makeSos(null, 'open');
        $customer = $sos->user;
        Sanctum::actingAs($customer);

        $this->postJson("/api/sos/{$sos->id}/cancel", ['cancellation_reason' => 'لم أعد بحاجة للمساعدة'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('cancelled', $sos->fresh()->status);
        $this->assertEquals(0, \Illuminate\Notifications\DatabaseNotification::count());
    }
}
