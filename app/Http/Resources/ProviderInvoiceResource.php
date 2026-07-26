<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProviderInvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'provider_type' => $this->provider_type,
            'provider_id' => $this->provider_id,
            'billing_setting_id' => $this->billing_setting_id,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'subtotal' => (float) $this->subtotal,
            'commission_total' => (float) $this->commission_total,
            'subscription_total' => (float) $this->subscription_total,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            // computed: 'overdue' when an issued invoice's due date has passed
            'effective_status' => $this->effectiveStatus(),
            'is_overdue' => $this->isOverdue(),
            'external_payment_method' => $this->external_payment_method,
            'external_payment_reference' => $this->external_payment_reference,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'confirmed_by' => $this->confirmed_by,
            'notes' => $this->notes,
            'items' => ProviderInvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
