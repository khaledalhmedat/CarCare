<?php

namespace App\Events;

use App\Models\FuelOrder;
use App\Models\FuelProvider;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FuelOrderCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FuelOrder $order,
        public FuelProvider $provider,
        public string $reason
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
        return 'order-cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'reason' => $this->reason,
            'status' => 'open',
        ];
    }
}
