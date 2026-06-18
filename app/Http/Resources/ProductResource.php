<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'discount_price' => $this->discount_price,
            'final_price' => $this->final_price,
            'stock_quantity' => $this->stock_quantity,
            'weight_kg' => $this->weight_kg,
            'dimensions' => $this->dimensions,
            'condition' => $this->condition,
            'is_featured' => $this->is_featured,
            'car_brand' => $this->carBrand?->name,
            'part_category' => $this->partCategory?->name,
            'images' => $this->images->map(fn($img) => $img->url),
            'primary_image' => $this->primaryImage?->url,
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }
}