<?php

namespace App\Events;

use App\Models\SosRequest;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime counterpart to SosService::cancelRequest() (customer-initiated
 * cancellation). Kept separate from SosRequestCancelled, whose payload/actor
 * semantics represent a technician-initiated cancellation instead.
 */
class SosCancelledByCustomer implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SosRequest $sosRequest,
        public User $technician,
        public string $reason
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('sos.' . $this->sosRequest->id),
            new Channel('technician.' . $this->technician->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sos-cancelled-by-customer';
    }

    public function broadcastWith(): array
    {
        return [
            'sos_request_id' => $this->sosRequest->id,
            'status' => 'cancelled',
            'reason' => $this->reason,
        ];
    }
}
