<?php

// للتذكير: هذا الملف يختبر الإشعارات الدائمة لتحولات طلب الطوارئ (قبول، تحديث حالة، إلغاء الفني).

namespace Tests\Feature;

use App\Models\SosRequest;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class SosNotificationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeTechnician(): User
    {
        $techUser = $this->makeUserWithRole('technician');
        Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => 'approved',
        ]);

        return $techUser;
    }

    private function makeSos(?User $techUser, string $status): array
    {
        $customer = $this->makeUser();
        $vehicle = Vehicle::create([
            'user_id' => $customer->id, 'brand' => 'Kia', 'model' => 'Rio',
            'year' => 2019, 'plate_number' => 'SN-' . uniqid(),
        ]);

        $sos = SosRequest::create([
            'user_id' => $customer->id, 'vehicle_id' => $vehicle->id,
            'technician_id' => $techUser?->id, 'lat' => 33.54, 'lng' => 36.32, 'status' => $status,
        ]);

        return [$customer, $sos];
    }

    public function test_accept_notifies_customer_only(): void
    {
        $tech = $this->makeTechnician();
        [$customer, $sos] = $this->makeSos(null, 'open');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $tech->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('sos_accepted', $notification->type);
        $this->assertEquals('sos_accepted', $notification->data['type']);
        $this->assertEquals('تم قبول طلب الطوارئ', $notification->data['title']);
        $this->assertEquals('تم قبول طلب الطوارئ الخاص بك، والفني في طريقه إليك', $notification->data['body']);
        $this->assertEquals([
            'entity_type' => 'sos_request',
            'entity_id' => $sos->id,
            'action' => 'open_details',
            'status' => 'accepted',
            'technician_id' => $tech->id,
        ], $notification->data['data']);
    }

    public function test_status_in_progress_notifies_customer_only(): void
    {
        $tech = $this->makeTechnician();
        [$customer, $sos] = $this->makeSos($tech, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/sos/requests/{$sos->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $tech->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('sos_in_progress', $notification->type);
        $this->assertEquals([
            'entity_type' => 'sos_request',
            'entity_id' => $sos->id,
            'action' => 'open_details',
            'status' => 'in_progress',
            'technician_id' => $tech->id,
        ], $notification->data['data']);
    }

    public function test_status_completed_notifies_customer_only(): void
    {
        $tech = $this->makeTechnician();
        [$customer, $sos] = $this->makeSos($tech, 'in_progress');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/sos/requests/{$sos->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $tech->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('sos_completed', $notification->type);
        $this->assertEquals([
            'entity_type' => 'sos_request',
            'entity_id' => $sos->id,
            'action' => 'open_details',
            'status' => 'completed',
            'technician_id' => $tech->id,
        ], $notification->data['data']);
    }

    public function test_repeated_invalid_status_transition_creates_no_duplicate_notification(): void
    {
        $tech = $this->makeTechnician();
        [$customer, $sos] = $this->makeSos($tech, 'accepted');
        Sanctum::actingAs($tech);

        $this->patchJson("/api/technician/sos/requests/{$sos->id}/status", ['status' => 'in_progress'])
            ->assertOk();
        $this->assertEquals(1, $customer->notifications()->count());

        $this->patchJson("/api/technician/sos/requests/{$sos->id}/status", ['status' => 'in_progress'])
            ->assertStatus(500)
            ->assertJson(['success' => false]);

        $this->assertEquals(1, $customer->notifications()->count());
    }

    public function test_technician_cancel_reopens_and_notifies_customer_only(): void
    {
        $tech = $this->makeTechnician();
        [$customer, $sos] = $this->makeSos($tech, 'accepted');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/cancel", ['cancellation_reason' => 'ظرف طارئ'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('open', $sos->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
        $this->assertEquals(0, $tech->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $customer->notifications()->first();
        $this->assertEquals('sos_reopened_after_technician_cancel', $notification->type);
        $this->assertEquals([
            'entity_type' => 'sos_request',
            'entity_id' => $sos->id,
            'action' => 'open_details',
            'status' => 'open',
            'technician_id' => $tech->id,
            'reason' => 'ظرف طارئ',
        ], $notification->data['data']);
    }

    public function test_accept_succeeds_when_notification_broadcast_fails(): void
    {
        $tech = $this->makeTechnician();
        [$customer, $sos] = $this->makeSos(null, 'open');

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($tech);

        $this->postJson("/api/technician/sos/requests/{$sos->id}/accept", [])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('accepted', $sos->fresh()->status);
        $this->assertEquals(1, $customer->notifications()->count());
    }
}
