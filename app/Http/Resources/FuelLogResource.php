<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FuelLogResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'amount' => $this->amount,
            'cost' => $this->cost,
            'km_at_fill' => $this->km_at_fill,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}