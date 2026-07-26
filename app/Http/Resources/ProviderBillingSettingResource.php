<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProviderBillingSettingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'provider_type' => $this->provider_type,
            'provider_id' => $this->provider_id,
            'billing_type' => $this->billing_type,
            'monthly_fee' => $this->monthly_fee !== null ? (float) $this->monthly_fee : null,
            'commission_percent' => $this->commission_percent !== null ? (float) $this->commission_percent : null,
            'free_trial_days' => (int) $this->free_trial_days,
            'payment_due_days' => (int) $this->payment_due_days,
            'starts_at' => $this->starts_at?->toDateString(),
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
