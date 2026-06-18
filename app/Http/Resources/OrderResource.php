<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'shop' => new ShopResource($this->shop),
            'items' => OrderItemResource::collection($this->items),
            'total_price' => $this->total_price,
            'status' => $this->status,
            'status_text' => $this->status_text,
            'delivery_address_note' => $this->delivery_address_note,
            'customer_latitude' => $this->customer_latitude,
            'customer_longitude' => $this->customer_longitude,
            'created_at' => $this->created_at->toDateTimeString(),
            'can_cancel' => $this->canCancel(),
        ];
    }
}