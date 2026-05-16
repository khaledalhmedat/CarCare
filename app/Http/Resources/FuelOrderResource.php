<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FuelOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = [
            'id' => $this->id,
            'fuel_type' => $this->fuel_type,
            'amount' => $this->amount,
            'delivery_address' => $this->delivery_address,
            'delivery_latitude' => $this->delivery_latitude,
            'delivery_longitude' => $this->delivery_longitude,
            'total_price' => $this->total_price,
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            'scheduled_time' => $this->scheduled_time?->toDateTimeString(),
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'notes' => $this->notes,
            'created_at' => $this->created_at->toDateTimeString(),
            'can_cancel' => in_array($this->status, ['pending', 'accepted']),
        ];

        if ($this->fuelProvider && ($this->status === 'accepted' || $this->status === 'in_progress')) {
            $data['fuel_provider'] = [
                'id' => $this->fuelProvider->id,
                'company_name' => $this->fuelProvider->company_name,
                'phone' => $this->fuelProvider->phone,
                'current_location' => [
                    'lat' => (float) $this->fuelProvider->latitude,
                    'lng' => (float) $this->fuelProvider->longitude,
                    'updated_at' => $this->fuelProvider->updated_at?->toDateTimeString(),
                ],
            ];
        }

        return $data;
    }

    private function getStatusText(): string
    {
        return match ($this->status) {
            'pending' => 'قيد الانتظار',
            'accepted' => 'تم القبول',
            'in_progress' => 'جاري التوصيل',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }
}
