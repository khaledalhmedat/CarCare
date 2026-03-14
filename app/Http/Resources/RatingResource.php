<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RatingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'review' => $this->review,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
            'technician' => [
                'id' => $this->technician->id,
                'name' => $this->technician->name,
            ],
            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
        ];
    }
}
