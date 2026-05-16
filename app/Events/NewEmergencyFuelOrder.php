<?php

namespace App\Events;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewEmergencyFuelOrder implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?FuelProvider $provider;
    public ?float $distance;

    public function __construct(
        public FuelOrder $order,
        ?FuelProvider $provider = null,
        ?float $distance = null
    ) {
        $this->provider = $provider;
        $this->distance = $distance;
    }

    public function broadcastOn(): array
    {
        if ($this->provider) {
            return [
                new Channel('fuel-provider.' . $this->provider->user_id),
            ];
        }

        return [
            new Channel('fuel-orders'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'new-emergency-fuel-order';
    }

    public function broadcastWith(): array
    {
        $data = [
            'order_id' => $this->order->id,
            'fuel_type' => $this->order->fuel_type,
            'amount' => $this->order->amount,
            'delivery_latitude' => $this->order->delivery_latitude,
            'delivery_longitude' => $this->order->delivery_longitude,
            'city' => $this->order->city,
            'notes' => $this->order->notes,
            'vehicle' => [
                'brand' => $this->order->vehicle->brand,
                'model' => $this->order->vehicle->model,
                'plate_number' => $this->order->vehicle->plate_number,
            ],
            'created_at' => $this->order->created_at->toIso8601String(),
        ];

        if ($this->distance !== null) {
            $data['distance'] = $this->distance;
            $data['distance_text'] = $this->distance . ' كم';
        }

        return $data;
    }
}
