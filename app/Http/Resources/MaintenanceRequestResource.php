<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MaintenanceRequestResource extends JsonResource
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

            'vehicle' => new VehicleResource($this->whenLoaded('vehicle')),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],

            'images' => $this->whenLoaded('photos', function () {
                return $this->photos->map(function ($photo) {
                    return [
                        'id' => $photo->id,
                        'url' => asset('storage/' . $photo->path),
                    ];
                });
            }, []),

            'images_count' => $this->whenCounted('photos', 0),

            'quotations' => $this->whenLoaded('quotations', function () {
                return $this->quotations->map(function ($quotation) {
                    return [
                        'id' => $quotation->id,
                        'technician_name' => $quotation->technician->name,
                        'price' => $quotation->price,
                        'status' => $quotation->status,
                        'notes' => $quotation->notes,
                        'created_at' => $quotation->created_at->toDateTimeString(),
                    ];
                });
            }),

            'has_accepted_quotation' => $this->quotations()
                ->where('status', 'accepted')
                ->exists(),

            'preferred_date' => $this->preferred_date ?
                \Carbon\Carbon::parse($this->preferred_date)->format('Y-m-d') :
                null,
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
            'updated_at' => $this->updated_at->toDateTimeString(),

            'can_cancel' => in_array($this->status, ['pending', 'quoted']),
            'can_edit' => $this->status === 'pending',
            'can_accept_quotation' => $this->status === 'quoted',
        ];
    }

    private function getPriorityText(): string
    {
        if (!$this->priority) {
            return 'غير محدد';
        }

        return match ($this->priority) {
            'low' => 'منخفضة',
            'medium' => 'متوسطة',
            'high' => 'عالية',
            'emergency' => 'طارئة',
            default => $this->priority,
        };
    }

    private function getStatusText(): string
    {
        if (!$this->status) {
            return 'غير محدد';
        }

        return match ($this->status) {
            'pending' => 'بانتظار العروض',
            'quoted' => 'تم استلام عروض',
            'quotation_accepted' => 'تم قبول العرض',
            'in_progress' => 'جاري التنفيذ',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }
}
