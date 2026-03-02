<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'plate_number' => $this->plate_number,
            'current_km' => $this->current_km,
            
            'owner' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
        
            'status' => $this->getMaintenanceStatus(),
            'needs_maintenance' => $this->needsMaintenance(),
            
            'created_at' => $this->created_at->toDateTimeString(),
            'updated_at' => $this->updated_at->toDateTimeString(),
        ];
    }

    protected function getMaintenanceStatus(): string
    {
        if ($this->relationLoaded('maintenanceRecords')) {
            $lastMaintenance = $this->maintenanceRecords->last();
            
            if (!$lastMaintenance) {
                return 'no_maintenance';
            }
            
            $daysSinceLastMaintenance = now()->diffInDays($lastMaintenance->created_at);
            
            if ($daysSinceLastMaintenance > 180) { 
                return 'overdue';
            } elseif ($daysSinceLastMaintenance > 150) { 
                return 'due_soon';
            }
        }
        
        return 'good';
    }

    protected function needsMaintenance(): bool
    {
        if ($this->relationLoaded('maintenanceAlerts')) {
            return $this->maintenanceAlerts
                ->where('is_active', true)
                ->isNotEmpty();
        }
        
        return false;
    }
}