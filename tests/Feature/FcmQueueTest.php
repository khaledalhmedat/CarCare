<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Jobs\SendFcmToDevice;
use App\Models\UserDeviceRegistration;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

class FcmQueueTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    private function makeRegistration($user, array $overrides = []): UserDeviceRegistration
    {
        return UserDeviceRegistration::create(array_merge([
            'user_id' => $user->id,
            'fcm_token' => 'token-' . Str::random(24),
            'platform' => 'android',
            'device_id' => 'device-' . Str::random(8),
            'is_active' => true,
            'failed_count' => 0,
            'last_used_at' => null,
        ], $overrides));
    }

    public function test_zero_active_registrations_dispatches_nothing(): void
    {
        Bus::fake();
        $user = $this->makeUser();

        (new SendFcmNotification($user->id, 'sos_accepted', 'title', 'body'))->handle();

        Bus::assertNotDispatched(SendFcmToDevice::class);
    }

    public function test_one_active_registration_dispatches_exactly_once(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user);

        (new SendFcmNotification($user->id, 'sos_accepted', 'title', 'body'))->handle();

        Bus::assertDispatchedTimes(SendFcmToDevice::class, 1);
        Bus::assertDispatched(SendFcmToDevice::class, fn ($job) => $job->registrationId === $registration->id);
    }

    public function test_multiple_active_registrations_fan_out_exactly_once_each(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $regA = $this->makeRegistration($user);
        $regB = $this->makeRegistration($user);
        $regC = $this->makeRegistration($user);

        (new SendFcmNotification($user->id, 'sos_accepted', 'title', 'body'))->handle();

        Bus::assertDispatchedTimes(SendFcmToDevice::class, 3);
        foreach ([$regA->id, $regB->id, $regC->id] as $id) {
            Bus::assertDispatched(SendFcmToDevice::class, fn ($job) => $job->registrationId === $id);
        }
    }

    public function test_inactive_registration_is_skipped(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $active = $this->makeRegistration($user);
        $inactive = $this->makeRegistration($user, ['is_active' => false]);

        (new SendFcmNotification($user->id, 'sos_accepted', 'title', 'body'))->handle();

        Bus::assertDispatchedTimes(SendFcmToDevice::class, 1);
        Bus::assertDispatched(SendFcmToDevice::class, fn ($job) => $job->registrationId === $active->id);
    }

    public function test_type_is_included_in_data_when_missing(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $this->makeRegistration($user);

        (new SendFcmNotification($user->id, 'sos_accepted', 'title', 'body', ['entity_id' => 5]))->handle();

        Bus::assertDispatched(SendFcmToDevice::class, fn ($job) => $job->data['type'] === 'sos_accepted' && $job->data['entity_id'] === 5);
    }

    public function test_explicit_type_in_data_is_not_overwritten(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $this->makeRegistration($user);

        (new SendFcmNotification($user->id, 'sos_accepted', 'title', 'body', ['type' => 'custom_type']))->handle();

        Bus::assertDispatched(SendFcmToDevice::class, fn ($job) => $job->data['type'] === 'custom_type');
    }

    public function test_missing_user_finishes_safely(): void
    {
        Bus::fake();

        (new SendFcmNotification(999999, 'sos_accepted', 'title', 'body'))->handle();

        Bus::assertNotDispatched(SendFcmToDevice::class);
    }

    public function test_deleted_registration_is_skipped_safely(): void
    {
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user);
        $id = $registration->id;
        $registration->delete();

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldNotReceive('sendToToken');

        $job = new SendFcmToDevice($id, 'title', 'body');
        $job->handle($fcm);

        $this->assertTrue(true);
    }

    public function test_inactive_device_job_is_skipped_safely(): void
    {
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user, ['is_active' => false]);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldNotReceive('sendToToken');

        $job = new SendFcmToDevice($registration->id, 'title', 'body');
        $job->handle($fcm);

        $this->assertTrue(true);
    }

    public function test_uses_current_token_not_stale_queued_token(): void
    {
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user, ['fcm_token' => 'old-token']);

        $job = new SendFcmToDevice($registration->id, 'title', 'body');

        $registration->update(['fcm_token' => 'new-token']);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')
            ->once()
            ->with('new-token', 'title', 'body', [])
            ->andReturn(['success' => true, 'error_code' => null]);

        $job->handle($fcm);
    }

    public function test_successful_send_resets_failed_count_and_updates_last_used_at(): void
    {
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user, ['failed_count' => 4, 'last_used_at' => null]);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')->once()->andReturn(['success' => true, 'error_code' => null]);

        (new SendFcmToDevice($registration->id, 'title', 'body'))->handle($fcm);

        $fresh = $registration->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertSame(0, $fresh->failed_count);
        $this->assertNotNull($fresh->last_used_at);
    }

    public function permanentErrorProvider(): array
    {
        return [
            ['UNREGISTERED'],
            ['INVALID_ARGUMENT'],
            ['SENDER_ID_MISMATCH'],
        ];
    }

    public function test_unregistered_deactivates_only_that_registration(): void
    {
        $this->assertPermanentErrorDeactivatesOnlyTarget('UNREGISTERED');
    }

    public function test_invalid_argument_deactivates_only_that_registration(): void
    {
        $this->assertPermanentErrorDeactivatesOnlyTarget('INVALID_ARGUMENT');
    }

    public function test_sender_id_mismatch_deactivates_only_that_registration(): void
    {
        $this->assertPermanentErrorDeactivatesOnlyTarget('SENDER_ID_MISMATCH');
    }

    private function assertPermanentErrorDeactivatesOnlyTarget(string $errorCode): void
    {
        $user = $this->makeUser();
        $target = $this->makeRegistration($user);
        $other = $this->makeRegistration($user);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')->once()->andReturn(['success' => false, 'error_code' => $errorCode]);

        (new SendFcmToDevice($target->id, 'title', 'body'))->handle($fcm);

        $this->assertFalse($target->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_unavailable_keeps_registration_active_and_retries_only_that_device(): void
    {
        $this->assertTransientErrorKeepsActiveAndThrows('UNAVAILABLE');
    }

    public function test_quota_exceeded_keeps_registration_active_and_retries_only_that_device(): void
    {
        $this->assertTransientErrorKeepsActiveAndThrows('QUOTA_EXCEEDED');
    }

    public function test_internal_keeps_registration_active_and_retries_only_that_device(): void
    {
        $this->assertTransientErrorKeepsActiveAndThrows('INTERNAL');
    }

    private function assertTransientErrorKeepsActiveAndThrows(string $errorCode): void
    {
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user, ['failed_count' => 0]);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')->once()->andReturn(['success' => false, 'error_code' => $errorCode]);

        $this->expectException(\RuntimeException::class);

        try {
            (new SendFcmToDevice($registration->id, 'title', 'body'))->handle($fcm);
        } finally {
            $fresh = $registration->fresh();
            $this->assertTrue($fresh->is_active);
            $this->assertSame(1, $fresh->failed_count);
        }
    }

    public function test_one_failed_device_does_not_block_another_device(): void
    {
        $user = $this->makeUser();
        $regA = $this->makeRegistration($user);
        $regB = $this->makeRegistration($user);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')->once()->with($regA->fcm_token, 'title', 'body', [])
            ->andReturn(['success' => false, 'error_code' => 'UNAVAILABLE']);
        $fcm->shouldReceive('sendToToken')->once()->with($regB->fcm_token, 'title', 'body', [])
            ->andReturn(['success' => true, 'error_code' => null]);

        try {
            (new SendFcmToDevice($regA->id, 'title', 'body'))->handle($fcm);
        } catch (\RuntimeException $e) {
        }

        (new SendFcmToDevice($regB->id, 'title', 'body'))->handle($fcm);

        $this->assertTrue($regB->fresh()->is_active);
        $this->assertSame(0, $regB->fresh()->failed_count);
    }

    public function test_retrying_a_failed_device_does_not_resend_to_an_already_succeeded_device(): void
    {
        $user = $this->makeUser();
        $regA = $this->makeRegistration($user);
        $regB = $this->makeRegistration($user);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')->once()->with($regA->fcm_token, 'title', 'body', [])
            ->andReturn(['success' => true, 'error_code' => null]);
        $fcm->shouldReceive('sendToToken')->twice()->with($regB->fcm_token, 'title', 'body', [])
            ->andReturn(['success' => false, 'error_code' => 'INTERNAL']);

        (new SendFcmToDevice($regA->id, 'title', 'body'))->handle($fcm);

        for ($i = 0; $i < 2; $i++) {
            try {
                (new SendFcmToDevice($regB->id, 'title', 'body'))->handle($fcm);
            } catch (\RuntimeException $e) {
            }
        }

        $this->assertTrue($regA->fresh()->is_active);
        $this->assertSame(0, $regA->fresh()->failed_count);
    }

    public function test_raw_fcm_token_never_appears_in_serialized_job_payload(): void
    {
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user, ['fcm_token' => 'super-secret-token-xyz']);

        $job = new SendFcmToDevice($registration->id, 'title', 'body', ['entity_id' => 1]);

        $serialized = serialize($job);

        $this->assertStringNotContainsString('super-secret-token-xyz', $serialized);
    }

    public function test_raw_fcm_token_never_appears_in_logs(): void
    {
        Log::spy();
        $user = $this->makeUser();
        $registration = $this->makeRegistration($user, ['fcm_token' => 'super-secret-log-token']);

        $fcm = \Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToToken')->once()->andReturn(['success' => false, 'error_code' => 'INTERNAL']);

        try {
            (new SendFcmToDevice($registration->id, 'title', 'body'))->handle($fcm);
        } catch (\RuntimeException $e) {
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, $context = []) {
                $this->assertStringNotContainsString('super-secret-log-token', $message);
                $this->assertStringNotContainsString('super-secret-log-token', json_encode($context));

                return true;
            });
    }

}
