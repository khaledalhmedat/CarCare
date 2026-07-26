<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\BillingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBillingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route already guarded by auth:sanctum + role:admin
    }

    public function rules(): array
    {
        $providerTable = $this->providerTableForType($this->input('provider_type'));

        return [
            'provider_type' => ['required', Rule::in(BillingService::PROVIDER_TYPES)],
            'provider_id' => [
                'required',
                'integer',
                $providerTable
                    ? Rule::exists($providerTable, 'id')
                    : 'integer',
            ],
            'billing_type' => ['required', Rule::in(BillingService::BILLING_TYPES)],
            // monthly_fee required only when the billing type includes a subscription
            'monthly_fee' => [
                Rule::requiredIf(fn () => in_array($this->input('billing_type'), ['monthly_subscription', 'subscription_plus_commission'], true)),
                'nullable',
                'numeric',
                'min:0',
            ],
            // commission_percent required only when the billing type includes commission
            'commission_percent' => [
                Rule::requiredIf(fn () => in_array($this->input('billing_type'), ['commission_per_order', 'subscription_plus_commission'], true)),
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'free_trial_days' => ['nullable', 'integer', 'min:0'],
            'payment_due_days' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function providerTableForType(?string $type): ?string
    {
        return match ($type) {
            'technician' => 'technicians',
            'car-washer' => 'car_washers',
            'fuel-provider' => 'fuel_providers',
            'shop' => 'shops',
            default => null,
        };
    }

    public function messages(): array
    {
        return [
            'provider_id.exists' => 'مزود الخدمة المحدد غير موجود',
            'commission_percent.max' => 'نسبة العمولة يجب ألا تتجاوز 100',
        ];
    }
}
