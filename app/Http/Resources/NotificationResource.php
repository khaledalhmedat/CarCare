<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray($request): array
    {
        $payload = $this->data ?? [];

        return [
            'id' => $this->id,
            'type' => $payload['type'] ?? $this->type,
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? null,
            'data' => $payload['data'] ?? [],
            'read_at' => optional($this->read_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
