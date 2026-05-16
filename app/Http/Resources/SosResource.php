<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SosResource extends JsonResource
{

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'city' => $this->city,
            'description' => $this->description,
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            'priority' => $this->priority,
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'technician' => $this->whenLoaded('technician', fn() => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
                'phone' => $this->technician->phone,
            ]),
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
            'can_cancel' => in_array($this->status, ['open', 'accepted']),
        ];
    }

    private function getStatusText(): string
    {
        return match ($this->status) {
            'open' => 'قيد الانتظار',
            'accepted' => 'تم الاستجابة',
            'in_progress' => 'جاري التنفيذ',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }
}
