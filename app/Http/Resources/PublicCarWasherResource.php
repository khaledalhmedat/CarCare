<?php

// للتذكير: هذا الملف يعرض الحقول العامة الآمنة للمغسلة لتطبيق الجوال فقط (قائمة وتفاصيل).

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PublicCarWasherResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'shop_name' => $this->shop_name,
            'phone' => $this->phone,
            'city' => $this->city,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'logo' => $this->logo ? asset('storage/' . $this->logo) : null,
            'services' => $this->services,
            'service_prices' => $this->service_prices,
            'working_hours' => $this->working_hours,
            'description' => $this->description,
            'is_available' => $this->is_available,
            'is_verified' => $this->is_verified,
            'average_rating' => round($this->average_rating, 2),
            'ratings_count' => (int) $this->ratings_count,
            'rating_stars' => $this->getRatingStars(),
        ];
    }

    private function getRatingStars(): string
    {
        $fullStars = floor($this->average_rating);
        $halfStar = ($this->average_rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        return str_repeat('⭐', $fullStars) . ($halfStar ? '½' : '') . str_repeat('☆', $emptyStars);
    }
}
