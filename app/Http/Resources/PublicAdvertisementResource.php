<?php

// للتذكير: هذا الملف يعرض الحقول العامة الآمنة للإعلان لتطبيق الجوال فقط.

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

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
