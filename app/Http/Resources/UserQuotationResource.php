<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Helpers\TechnicianLocationHelper;

class UserQuotationResource extends JsonResource
{
    use TechnicianLocationHelper;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'price' => $this->price,
            'price_formatted' => number_format($this->price, 2) . ' ليرة سورية',
            'estimated_days' => $this->estimated_days,
            'notes' => $this->notes,
            'parts_included' => $this->parts_included ?? false,
            'status' => $this->status,
            'status_text' => $this->status === 'pending' ? 'قيد الانتظار' : 
                            ($this->status === 'accepted' ? 'مقبول' : 'مرفوض'),
            
            'technician' => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
                'phone' => $this->technician->phone,
                'technician_profile' => $this->technician->technician ? [
                    'specialization' => $this->technician->technician->specialization,
                    'experience_years' => $this->technician->technician->experience_years,
                    'current_location' => $this->formatTechnicianLocation($this->technician->technician),
                ] : null,
            ],
            
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
        ];
    }
}