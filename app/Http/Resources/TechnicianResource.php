<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TechnicianResource extends JsonResource
{
    public function toArray($request): array
    {
        // certifications may arrive as an array (model cast), a JSON string, or a
        // double-encoded JSON string from older rows — normalize all three.
        $certifications = $this->certifications;

        if (is_string($certifications)) {
            $certifications = json_decode($certifications, true);

            if (is_string($certifications)) {
                $certifications = json_decode($certifications, true);
            }
        }

        if (!is_array($certifications)) {
            $certifications = [];
        }

        $certificationUrls = array_map(function ($path) {
            return asset('storage/' . $path);
        }, $certifications);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'specialization' => $this->specialization,
            'experience_years' => $this->experience_years,
            'phone' => $this->phone,
            'city' => $this->city,
            'hourly_rate' => $this->hourly_rate,
            'is_available' => $this->is_available,

            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'rejected_at' => $this->rejected_at?->toDateTimeString(),
            'suspended_at' => $this->suspended_at?->toDateTimeString(),

            'certifications' => $certificationUrls,
            'certifications_raw' => $certifications,

            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
