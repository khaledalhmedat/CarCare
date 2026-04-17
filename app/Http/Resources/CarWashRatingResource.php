<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CarWashRatingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'rating_stars' => str_repeat('⭐', $this->rating),
            'review' => $this->review,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
        ];
    }
}