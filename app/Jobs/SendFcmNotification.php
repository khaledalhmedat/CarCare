<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            return;
        }

        $data = $this->data + ['type' => $this->type];

        $registrationIds = $user->deviceRegistrations()
            ->where('is_active', true)
            ->pluck('id');

        foreach ($registrationIds as $registrationId) {
            SendFcmToDevice::dispatch($registrationId, $this->title, $this->body, $data);
        }
    }
}
