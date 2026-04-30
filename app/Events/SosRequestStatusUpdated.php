<?php

namespace App\Events;

use App\Models\SosRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SosRequestStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SosRequest $sosRequest) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('sos.' . $this->sosRequest->id),
            new Channel('user.' . $this->sosRequest->user_id),
            new Channel('technician.' . $this->sosRequest->technician_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sos-status-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->sosRequest->id,
            'status' => $this->sosRequest->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}