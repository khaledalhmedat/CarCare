<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Helpers\TechnicianLocationHelper;
use App\Http\Resources\UserQuotationResource;

class MaintenanceRequestResource extends JsonResource
{
    use TechnicianLocationHelper;

    public function toArray($request): array
    {
        $data = [
            'id' => $this->id,
            'description' => $this->description,
            'priority' => $this->priority,
            'priority_text' => $this->getPriorityText(),
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            
            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            
            'images' => $this->whenLoaded('photos', function() {
                return $this->photos->map(fn($photo) => [
                    'id' => $photo->id,
                    'url' => asset('storage/' . $photo->path),
                ]);
            }, []),
            
            'quotations' => UserQuotationResource::collection($this->whenLoaded('quotations')),
            
            'preferred_date' => $this->preferred_date,
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
            
            'can_cancel' => in_array($this->status, ['pending', 'quoted']),
        ];
        
        if (in_array($this->status, ['quotation_accepted', 'in_progress', 'completed'])) {
            $acceptedQuotation = $this->quotations->firstWhere('status', 'accepted');
            
            if ($acceptedQuotation && $acceptedQuotation->technician) {
                $technician = $acceptedQuotation->technician->technician;
                
                $data['assigned_technician'] = [
                    'id' => $acceptedQuotation->technician->id,
                    'name' => $acceptedQuotation->technician->name,
                    'phone' => $acceptedQuotation->technician->phone,
                    'specialization' => $technician?->specialization,
                    'experience_years' => $technician?->experience_years,
                    'hourly_rate' => $technician?->hourly_rate,
                    'current_location' => $this->formatTechnicianLocation($technician),
                ];
            }
        }
        
        if ($this->status === 'completed' && $this->serviceJob && $this->serviceJob->maintenanceRecord) {
            $data['maintenance_record'] = new MaintenanceRecordResource($this->serviceJob->maintenanceRecord);
        }
        
        return $data;
    }
    
    private function getPriorityText(): string
    {
        return match($this->priority) {
            'low' => 'منخفضة',
            'medium' => 'متوسطة',
            'high' => 'عالية',
            'emergency' => 'طارئة',
            default => $this->priority ?? 'غير محدد',
        };
    }
    
    private function getStatusText(): string
    {
        return match($this->status) {
            'pending' => 'بانتظار العروض',
            'quoted' => 'تم استلام عروض',
            'quotation_accepted' => 'تم قبول العرض',
            'in_progress' => 'جاري التنفيذ',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default => $this->status ?? 'غير محدد',
        };
    }
}