<?php

namespace App\Http\Resources;

use App\Http\Resources\VehicleResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\JsonResource;

class TechnicianMaintenanceRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'priority' => $this->priority,
            'priority_text' => $this->getPriorityText(),
            'status' => $this->status,
            'status_text' => $this->getStatusText(),
            
            'customer' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ],
            
            'vehicle' => [
                'id' => $this->vehicle->id,
                'brand' => $this->vehicle->brand,
                'model' => $this->vehicle->model,
                'year' => $this->vehicle->year,
                'plate_number' => $this->vehicle->plate_number,
            ],
            
            'images' => $this->whenLoaded('photos', function() {
                return $this->photos->map(function($photo) {
                    return [
                        'id' => $photo->id,
                        'url' => asset('storage/' . $photo->path),
                    ];
                });
            }, []),
            
            'my_quotation' => $this->whenLoaded('quotations', function() use ($request) {
                $myQuotation = $this->quotations
                    ->where('technician_id', $request->user()->technician->id)
                    ->first();
                    
                return $myQuotation ? new TechnicianQuotationResource($myQuotation) : null;
            }),
            
            'preferred_date' => $this->preferred_date,
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
        ];
    }

    private function getPriorityText(): string
    {
        return match($this->priority) {
            'low' => 'منخفضة',
            'medium' => 'متوسطة',
            'high' => 'عالية',
            'emergency' => 'طارئة',
            default => $this->priority,
        };
    }

    private function getStatusText(): string
    {
        return match($this->status) {
            'pending' => 'بانتظار العروض',
            'quoted' => 'تم استلام عروض',
            'quotation_accepted' => 'تم قبول عرضك',
            'in_progress' => 'قيد التنفيذ',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }
}