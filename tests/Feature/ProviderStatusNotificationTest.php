<?php

// للتذكير: هذا الملف يختبر إشعارات تغييرات حالة مزود الخدمة (قبول/رفض/إيقاف/إعادة تفعيل) الصادرة من ProviderApprovalService.

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class ProviderStatusNotificationTest extends TestCase
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
                    ProviderStatusNotificationTest::$broadcastCaptures[] = [
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

    private function makeTechnician(string $status = 'pending'): array
    {
        $techUser = $this->makeUserWithRole('technician');
        $technician = Technician::create([
            'user_id' => $techUser->id, 'specialization' => 'm', 'experience_years' => 2,
            'phone' => '05', 'city' => 'دمشق', 'status' => $status,
        ]);

        return [$techUser, $technician];
    }

    public function test_approve_notifies_correct_provider(): void
    {
        [$techUser, $technician] = $this->makeTechnician('pending');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/provider-approvals/technician/{$technician->id}/approve")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(1, $techUser->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $techUser->notifications()->first();
        $this->assertEquals('provider_approved', $notification->type);
        $this->assertEquals('approved', $notification->data['data']['status']);
        $this->assertEquals($technician->id, $notification->data['data']['provider_id']);
        $this->assertEquals('technician', $notification->data['data']['provider_type']);
        $this->assertEquals('approved', $technician->fresh()->status);
    }

    public function test_reject_notifies_correct_provider(): void
    {
        [$techUser, $technician] = $this->makeTechnician('pending');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/provider-approvals/technician/{$technician->id}/reject", [
            'rejection_reason' => 'وثائق ناقصة',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(1, $techUser->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $techUser->notifications()->first();
        $this->assertEquals('provider_rejected', $notification->type);
        $this->assertEquals('rejected', $notification->data['data']['status']);
        $this->assertEquals('rejected', $technician->fresh()->status);
    }

    public function test_suspend_notifies_correct_provider(): void
    {
        [$techUser, $technician] = $this->makeTechnician('approved');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/provider-approvals/technician/{$technician->id}/suspend")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(1, $techUser->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $techUser->notifications()->first();
        $this->assertEquals('provider_suspended', $notification->type);
        $this->assertEquals('suspended', $notification->data['data']['status']);
        $this->assertEquals('suspended', $technician->fresh()->status);
    }

    public function test_reactivate_notifies_correct_provider(): void
    {
        [$techUser, $technician] = $this->makeTechnician('suspended');
        $unrelated = $this->makeUser();
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/provider-approvals/technician/{$technician->id}/reactivate")
            ->assertOk()->assertJson(['success' => true]);

        $this->assertEquals(1, $techUser->notifications()->count());
        $this->assertEquals(0, $unrelated->notifications()->count());

        $notification = $techUser->notifications()->first();
        $this->assertEquals('provider_reactivated', $notification->type);
        $this->assertEquals('approved', $notification->data['data']['status']);
        $this->assertEquals('approved', $technician->fresh()->status);
    }

    public function test_fcm_dispatch_and_generic_broadcast_are_triggered_through_notification_service(): void
    {
        $this->useSpyBroadcaster();
        // يُقصر التزييف على وظيفة FCM فقط؛ تزييف كل الـ Bus يعطّل أيضاً بثّ NotificationCreated
        // لأن ShouldBroadcastNow يُنفَّذ داخلياً عبر dispatchNow على نفس الـ Bus.
        Bus::fake([SendFcmNotification::class]);
        [$techUser, $technician] = $this->makeTechnician('pending');
        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/provider-approvals/technician/{$technician->id}/approve")->assertOk();

        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
        Bus::assertDispatched(SendFcmNotification::class, fn ($job) => $job->userId === $techUser->id);

        $generic = array_values(array_filter(
            self::$broadcastCaptures,
            fn ($c) => $c['event'] === 'notification.created'
        ));

        $this->assertCount(1, $generic);
        $this->assertSame(["private-notifications.{$techUser->id}"], $generic[0]['channels']);
        $this->assertSame('provider_approved', $generic[0]['payload']['type']);
    }

    public function test_business_status_transition_succeeds_even_when_notification_delivery_fails(): void
    {
        [$techUser, $technician] = $this->makeTechnician('pending');

        Broadcast::extend('boom', fn () => new class implements Broadcaster {
            public function auth($request) { throw new \RuntimeException('boom'); }
            public function validAuthenticationResponse($request, $result) { return $result; }
            public function broadcast(array $channels, $event, array $payload = []) { throw new \RuntimeException('broadcast unavailable'); }
        });
        config(['broadcasting.connections.boom' => ['driver' => 'boom'], 'broadcasting.default' => 'boom']);

        Sanctum::actingAs($this->makeAdmin());

        $this->postJson("/api/admin/provider-approvals/technician/{$technician->id}/approve")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals('approved', $technician->fresh()->status);
        $this->assertEquals(1, $techUser->notifications()->count());
    }
}
