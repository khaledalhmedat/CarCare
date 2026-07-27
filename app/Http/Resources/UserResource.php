<?php

// للتذكير: هذا الملف يُنسّق بيانات المستخدم الآمنة لواجهات المصادقة.

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'profile_image_path' => $this->avatar,
            'profile_image_url' => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'tenant' => $this->whenLoaded('tenant', function () {
                return [
                    'id' => $this->tenant->id,
                    'name' => $this->tenant->name,
                ];
            }),

            'roles' => $this->whenLoaded('roles', function () {
                return RoleResource::collection($this->roles);
            }),
        ];
    }
}
