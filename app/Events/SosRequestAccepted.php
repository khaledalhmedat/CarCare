<?php

namespace App\Events;

use App\Models\SosRequest;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SosRequestAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SosRequest $sosRequest,
        public User $technician
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('sos.' . $this->sosRequest->id),
            new Channel('user.' . $this->sosRequest->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sos-accepted';
    }

    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->sosRequest->id,
            'technician' => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
                'phone' => $this->technician->phone,
            ],
            'status' => 'accepted',
        ];
    }
}