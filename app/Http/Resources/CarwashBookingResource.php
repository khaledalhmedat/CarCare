<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarwashBookingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'service_type' => $this->service_type,
            'price' => $this->price,
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            'scheduled_at' => $this->scheduled_at->toDateTimeString(),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'car_washer' => $this->whenLoaded('carWasher', fn() => new CarWasherResource($this->carWasher)),
            'notes' => $this->notes,
            'created_at' => $this->created_at->toDateTimeString(),
            'can_cancel' => $this->canCancel(),
        ];
    }

   private function getStatusText(): string
{
    if (!$this->status) {
        return 'قيد الانتظار';
    }
    
    return match($this->status) {
        'pending' => 'قيد الانتظار',
        'accepted' => 'تم القبول',
        'in_progress' => 'جاري التنفيذ',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
        default => $this->status,
    };
}
}