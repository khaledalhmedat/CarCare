<?php

namespace App\Http\Resources\Provider;

use App\Http\Resources\ProviderInvoiceItemResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ProviderInvoiceReadResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'provider_type' => $this->provider_type,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'subtotal' => (float) $this->subtotal,
            'commission_total' => (float) $this->commission_total,
            'subscription_total' => (float) $this->subscription_total,
            'total_amount' => (float) $this->total_amount,
            'status' => $this->status,
            'effective_status' => $this->effectiveStatus(),
            'issued_at' => $this->issued_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'items' => ProviderInvoiceItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
