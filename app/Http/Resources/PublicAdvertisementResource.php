<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minimal public shape for the mobile app — no admin/audit fields
 * (created_by/updated_by, timestamps, image_path, is_active).
 */
class PublicAdvertisementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'image_url' => $this->image_url,
            'placement' => $this->placement,
            'link_url' => $this->link_url,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
