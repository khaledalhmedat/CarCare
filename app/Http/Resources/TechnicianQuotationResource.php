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
            
            'technician' => $this->whenLoaded('technician', function() {
                return [
                    'id' => $this->technician->id,
                    'name' => $this->technician->name,
                ];
            }),
        ];
    }
}