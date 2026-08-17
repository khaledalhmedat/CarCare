<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ShopResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'city' => $this->city,
            'is_active' => $this->is_active,

            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
            'suspended_at' => $this->suspended_at?->toDateTimeString(),

            'owner' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'business_types' => $this->businessTypes->pluck('name'),
            'car_brands' => $this->carBrands->pluck('name'),
            'part_categories' => $this->partCategories->pluck('name'),
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}