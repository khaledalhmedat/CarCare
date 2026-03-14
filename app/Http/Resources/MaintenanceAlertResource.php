<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceAlertResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'type' => $this->type,
            'value' => $this->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}
