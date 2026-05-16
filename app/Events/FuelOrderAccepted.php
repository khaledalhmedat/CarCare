<?php

namespace App\Events;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FuelOrderAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FuelOrder $order,
        public FuelProvider $provider
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('fuel-order.' . $this->order->id),
            new Channel('user.' . $this->order->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order-accepted';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'provider' => [
                'id' => $this->provider->id,
                'company_name' => $this->provider->company_name,
                'phone' => $this->provider->phone,
                'latitude' => $this->provider->latitude,
                'longitude' => $this->provider->longitude,
            ],
            'status' => 'accepted',
            'estimated_arrival_minutes' => $this->order->estimated_arrival_minutes,
        ];
    }
}
