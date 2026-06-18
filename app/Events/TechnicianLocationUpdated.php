<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TechnicianLocationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $sosRequestId,
        public int $technicianId,
        public float $lat,
        public float $lng,
        public ?float $heading = null,
        public ?float $speed = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('sos.' . $this->sosRequestId),
            new Channel('technician.' . $this->technicianId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'sos_request_id' => $this->sosRequestId,
            'technician_id' => $this->technicianId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'heading' => $this->heading,
            'speed' => $this->speed,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}