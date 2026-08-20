<?php

namespace App\Jobs;

use App\Models\UserDeviceRegistration;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFcmToDevice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PERMANENT_ERRORS = ['UNREGISTERED', 'INVALID_ARGUMENT', 'SENDER_ID_MISMATCH'];

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $registrationId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
    }

    public function handle(FcmService $fcm): void
    {
        $registration = UserDeviceRegistration::find($this->registrationId);

        if (!$registration || !$registration->is_active) {
            return;
        }

        $result = $fcm->sendToToken($registration->fcm_token, $this->title, $this->body, $this->data);

        if ($result['success']) {
            $registration->update([
                'is_active' => true,
                'failed_count' => 0,
                'last_used_at' => now(),
            ]);

            return;
        }

        $errorCode = $result['error_code'];

        if (in_array($errorCode, self::PERMANENT_ERRORS, true)) {
            $registration->update([
                'is_active' => false,
                'failed_count' => $registration->failed_count + 1,
            ]);

            return;
        }

        $registration->increment('failed_count');

        Log::warning('fcm.device_send_failed', [
            'registration_id' => $registration->id,
            'error_code' => $errorCode,
            'attempt' => $this->attempts(),
        ]);

        throw new \RuntimeException("fcm send failed for registration {$registration->id}: {$errorCode}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('fcm.device_send_exhausted', [
            'registration_id' => $this->registrationId,
        ]);
    }
}
