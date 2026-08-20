<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class NotificationServiceFcmIntegrationTest extends TestCase
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
                    NotificationServiceFcmIntegrationTest::$broadcastCaptures[] = [
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

    private function useBoomBroadcaster(): void
    {
        Broadcast::extend('boom', function () {
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
                    throw new \RuntimeException('broadcast unavailable');
                }
            };
        });

        config([
            'broadcasting.connections.boom' => ['driver' => 'boom'],
            'broadcasting.default' => 'boom',
        ]);
    }

    private function useThrowingDispatcher(): void
    {
        $real = $this->app->make(Dispatcher::class);

        $this->app->instance(Dispatcher::class, new class($real) implements Dispatcher {
            public function __construct(private Dispatcher $real)
            {
            }

            public function dispatch($command)
            {
                throw new \RuntimeException('queue down');
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->real->dispatchSync($command, $handler);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->real->dispatchNow($command, $handler);
            }

            public function hasCommandHandler($command)
            {
                return $this->real->hasCommandHandler($command);
            }

            public function getCommandHandler($command)
            {
                return $this->real->getCommandHandler($command);
            }

            public function pipeThrough(array $pipes)
            {
                return $this->real->pipeThrough($pipes);
            }

            public function map(array $map)
            {
                return $this->real->map($map);
            }
        });
    }

    public function test_notify_user_persists_db_notification_as_before(): void
    {
        $user = $this->makeUser();

        $notification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body', ['foo' => 'bar']);

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'type' => 'type_x']);
        $this->assertEquals('bar', $notification->fresh()->data['data']['foo']);
    }

    public function test_notify_user_still_broadcasts_notification_created(): void
    {
        $this->useSpyBroadcaster();
        $user = $this->makeUser();

        app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body', ['foo' => 'bar']);

        $this->assertCount(1, self::$broadcastCaptures);
        $this->assertSame('notification.created', self::$broadcastCaptures[0]['event']);
    }

    public function test_notify_user_dispatches_exactly_one_parent_fcm_job(): void
    {
        Bus::fake();
        $user = $this->makeUser();

        app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');

        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
    }

    public function test_dispatched_job_receives_correct_arguments(): void
    {
        Bus::fake();
        $user = $this->makeUser();

        app(NotificationService::class)->notifyUser($user, 'sos_accepted', 'Title', 'Body', ['entity_id' => 7]);

        Bus::assertDispatched(SendFcmNotification::class, function ($job) use ($user) {
            return $job->userId === $user->id
                && $job->type === 'sos_accepted'
                && $job->title === 'Title'
                && $job->body === 'Body'
                && $job->data['entity_id'] === 7;
        });
    }

    public function test_fcm_dispatch_failure_still_returns_persisted_notification(): void
    {
        $this->useThrowingDispatcher();
        $user = $this->makeUser();

        $notification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
    }

    public function test_fcm_dispatch_failure_does_not_prevent_reverb_broadcast(): void
    {
        $this->useSpyBroadcaster();
        $this->useThrowingDispatcher();
        $user = $this->makeUser();

        $notification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');

        $this->assertNotNull($notification);
        $this->assertCount(1, self::$broadcastCaptures);
    }

    public function test_reverb_failure_does_not_prevent_fcm_dispatch(): void
    {
        $this->useBoomBroadcaster();
        Bus::fake();
        $user = $this->makeUser();

        $notification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');

        $this->assertNotNull($notification);
        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
    }

    public function test_database_persistence_failure_skips_reverb_and_fcm(): void
    {
        Bus::fake();
        Str::createUuidsUsing(fn () => 'fixed-notification-uuid');

        try {
            $user = $this->makeUser();

            $first = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');
            $this->assertNotNull($first);

            $second = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');
            $this->assertNull($second);
        } finally {
            Str::createUuidsNormally();
        }

        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
    }

    public function test_no_raw_fcm_token_referenced_in_notification_service_source(): void
    {
        $source = file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringNotContainsString('fcm_token', $source);
    }

    public function test_no_raw_fcm_token_in_dispatched_job_arguments(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        \App\Models\UserDeviceRegistration::create([
            'user_id' => $user->id,
            'fcm_token' => 'super-secret-integration-token',
            'platform' => 'android',
            'is_active' => true,
        ]);

        app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');

        Bus::assertDispatched(SendFcmNotification::class, function ($job) {
            return !str_contains(json_encode($job->data), 'super-secret-integration-token');
        });
    }

    public function test_existing_data_is_preserved_in_db_and_reverb(): void
    {
        $this->useSpyBroadcaster();
        $user = $this->makeUser();
        $originalData = ['entity_type' => 'sos_request', 'entity_id' => 9];

        $notification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body', $originalData);

        $this->assertEquals($originalData, $notification->fresh()->data['data']);
        $this->assertEquals($originalData, self::$broadcastCaptures[0]['payload']['data']);
    }

    public function test_notification_id_added_only_to_fcm_payload_without_overwriting_explicit_value(): void
    {
        $this->useSpyBroadcaster();
        $user = $this->makeUser();

        $notification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body', ['entity_id' => 1]);

        $this->assertArrayNotHasKey('notification_id', $notification->fresh()->data['data']);
        $this->assertArrayNotHasKey('notification_id', self::$broadcastCaptures[0]['payload']['data']);

        Bus::fake();
        $reNotification = app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body', ['entity_id' => 1]);

        Bus::assertDispatched(SendFcmNotification::class, function ($job) use ($reNotification) {
            return $job->data['notification_id'] === (string) $reNotification->id;
        });
        $notificationWithExplicitId = app(NotificationService::class)->notifyUser(
            $user,
            'type_x',
            'Title',
            'Body',
            ['notification_id' => 'custom-explicit-id']
        );

        Bus::assertDispatched(SendFcmNotification::class, function ($job) {
            return $job->data['notification_id'] === 'custom-explicit-id';
        });
    }

    public function test_single_notify_user_call_dispatches_only_one_parent_job_regardless_of_device_count(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        \App\Models\UserDeviceRegistration::create([
            'user_id' => $user->id,
            'fcm_token' => 'token-a-' . Str::random(10),
            'platform' => 'android',
            'is_active' => true,
        ]);
        \App\Models\UserDeviceRegistration::create([
            'user_id' => $user->id,
            'fcm_token' => 'token-b-' . Str::random(10),
            'platform' => 'android',
            'is_active' => true,
        ]);

        app(NotificationService::class)->notifyUser($user, 'type_x', 'Title', 'Body');

        Bus::assertDispatchedTimes(SendFcmNotification::class, 1);
    }
}
