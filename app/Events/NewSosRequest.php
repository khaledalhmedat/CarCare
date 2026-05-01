<?php

namespace App\Events;

use App\Models\SosRequest;
use App\Models\Technician;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewSosRequest implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SosRequest $sosRequest,
        public ?Technician $technician = null,
        public ?float $distance = null
    ) {}

    public function broadcastOn(): array
    {
        if ($this->technician) {
            return [
                new Channel('technician.' . $this->technician->user_id)
            ];
        }
        
        return [
            new Channel('sos-requests')
        ];
    }

    public function broadcastAs(): string
    {
        return 'new-sos-request';
    }

    public function broadcastWith(): array
    {
        $data = [
            'id' => $this->sosRequest->id,
            'lat' => $this->sosRequest->lat,
            'lng' => $this->sosRequest->lng,
            'city' => $this->sosRequest->city,
            'description' => $this->sosRequest->description,
            'vehicle' => [
                'brand' => $this->sosRequest->vehicle->brand,
                'model' => $this->sosRequest->vehicle->model,
                'plate_number' => $this->sosRequest->vehicle->plate_number,
            ],
        ];
        
        if ($this->distance) {
            $data['distance'] = $this->distance; 
            $data['distance_text'] = $this->distance . ' كم';
        }
        
        return $data;
    }
}