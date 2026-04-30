<?php

namespace App\Events;

use App\Models\SosRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewSosRequest implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SosRequest $sosRequest) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('sos-requests'),
            new Channel('technician.' . $this->sosRequest->city)
        ];
    }

    public function broadcastAs(): string
    {
        return 'new-sos-request';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->sosRequest->id,
            'lat' => $this->sosRequest->lat,
            'lng' => $this->sosRequest->lng,
            'description' => $this->sosRequest->description,
            'vehicle' => [
                'brand' => $this->sosRequest->vehicle->brand,
                'model' => $this->sosRequest->vehicle->model,
            ]
        ];
    }
}