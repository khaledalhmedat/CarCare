<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'status' => $this->status,

            'stats' => [
                'total_vehicles' => $this->whenLoaded('vehicles', fn() => $this->vehicles->count(), 0),
                'total_maintenance_requests' => $this->whenLoaded('maintenanceRequests', fn() => $this->maintenanceRequests->count(), 0),
                'total_sos_requests' => $this->whenLoaded('sosRequests', fn() => $this->sosRequests->count(), 0),
            ],

            'created_at' => $this->created_at->toDateTimeString(),
            'created_ago' => $this->created_at->diffForHumans(),
            'updated_at' => $this->updated_at->toDateTimeString(),

            'profile_completed' => $this->isProfileCompleted(),
        ];
    }

    private function isProfileCompleted(): bool
    {
        return !empty($this->name) && !empty($this->email) && !empty($this->phone);
    }
}
