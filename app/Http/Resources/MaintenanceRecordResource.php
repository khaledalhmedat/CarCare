<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRecordResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'details' => $this->details,
            'parts_used' => $this->parts_used,
            'parts_used_text' => $this->formatPartsUsed(),
            'completion_notes' => $this->completion_notes,
            'recommendations' => $this->recommendations,

            'completed_at' => $this->completed_at?->toDateTimeString(),
            'completed_ago' => $this->completed_at?->diffForHumans(),

            'vehicle' => [
                'id' => $this->vehicle->id,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
                'plate_number' => $this->vehicle->plate_number,
            ],

            'technician' => $this->whenLoaded('serviceJob', function () {
                return [
                    'id' => $this->serviceJob->technician->id,
                    'name' => $this->serviceJob->technician->name,
                    'phone' => $this->serviceJob->technician->phone,
                ];
            }),

            'maintenance_request' => $this->whenLoaded('maintenanceRequest', function () {
                return [
                    'id' => $this->maintenanceRequest->id,
                    'description' => $this->maintenanceRequest->description,
                    'priority' => $this->maintenanceRequest->priority,
                ];
            }),
        ];
    }

    private function formatPartsUsed(): ?string
    {
        if (!$this->parts_used || !is_array($this->parts_used)) {
            return null;
        }

        return collect($this->parts_used)
            ->map(fn($part) => "{$part['name']} ({$part['quantity']})" . (isset($part['price']) ? " - {$part['price']} ريال" : ''))
            ->implode('، ');
    }
}
