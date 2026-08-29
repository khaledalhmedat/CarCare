<?php

namespace App\Events;

use App\Models\FuelOrder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FuelOrderStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FuelOrder $order) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('fuel-order.' . $this->order->id),
            new Channel('user.' . $this->order->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'status' => $this->order->status,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}