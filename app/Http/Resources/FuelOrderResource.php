<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FuelOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'fuel_type' => $this->fuel_type,
            'amount' => $this->amount,
            'delivery_address' => $this->delivery_address,
            'total_price' => $this->total_price,
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            'scheduled_time' => $this->scheduled_time?->toDateTimeString(),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'fuel_provider' => $this->whenLoaded('fuelProvider', fn() => new FuelProviderResource($this->fuelProvider)),
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
        'in_progress' => 'جاري التوصيل',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغي',
        default => $this->status,
    };
}
}