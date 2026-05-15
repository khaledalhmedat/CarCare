<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TechnicianQuotationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'maintenance_request_id' => $this->maintenance_request_id,
            'price' => $this->price,
            'price_formatted' => number_format($this->price, 2) . ' ليرة سورية',
            'estimated_days' => $this->estimated_days,
            'parts_included' => $this->parts_included ?? false,
            'notes' => $this->notes,
            'status' => $this->status,
            'status_text' => $this->status === 'pending' ? 'قيد الانتظار' : 
                            ($this->status === 'accepted' ? 'مقبول' : 'مرفوض'),
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
            
            'customer' => $this->whenLoaded('maintenanceRequest', function() {
                return [
                    'id' => $this->maintenanceRequest->user->id,
                    'name' => $this->maintenanceRequest->user->name,
                    'phone' => $this->maintenanceRequest->user->phone,
                    'current_location' => $this->getCustomerLocation(),
                ];
            }),
        ];
    }
    
    private function getCustomerLocation(): ?array
    {
        $user = $this->maintenanceRequest->user;
        
        if (!$user->latitude || !$user->longitude) {
            return null;
        }
        
        return [
            'lat' => (float) $user->latitude,
            'lng' => (float) $user->longitude,
        ];
    }
}