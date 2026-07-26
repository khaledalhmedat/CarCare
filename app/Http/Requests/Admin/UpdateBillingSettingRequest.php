<?php

namespace App\Http\Requests\Admin;

use App\Services\Admin\BillingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBillingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route already guarded by auth:sanctum + role:admin
    }

    /**
     * Update keeps provider_type/provider_id fixed (identity of the setting).
     * Only billing terms are editable. billing_type drives conditional requirements.
     */
    public function rules(): array
    {
        $billingType = $this->input('billing_type');

        return [
            'billing_type' => ['sometimes', 'required', Rule::in(BillingService::BILLING_TYPES)],
            'monthly_fee' => [
                Rule::requiredIf(fn () => in_array($billingType, ['monthly_subscription', 'subscription_plus_commission'], true)),
                'nullable',
                'numeric',
                'min:0',
            ],
            'commission_percent' => [
                Rule::requiredIf(fn () => in_array($billingType, ['commission_per_order', 'subscription_plus_commission'], true)),
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

    public function messages(): array
    {
        return [
            'commission_percent.max' => 'نسبة العمولة يجب ألا تتجاوز 100',
        ];
    }
}
