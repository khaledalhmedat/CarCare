<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderBillingSetting extends Model
{
    public const BILLING_MONTHLY = 'monthly_subscription';
    public const BILLING_COMMISSION = 'commission_per_order';
    public const BILLING_BOTH = 'subscription_plus_commission';
    public const BILLING_EXEMPT = 'exempt';

    protected $fillable = [
        'provider_type',
        'provider_id',
        'billing_type',
        'monthly_fee',
        'commission_percent',
        'free_trial_days',
        'payment_due_days',
        'starts_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'monthly_fee' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'free_trial_days' => 'integer',
        'payment_due_days' => 'integer',
        'starts_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function invoices()
    {
        return $this->hasMany(ProviderInvoice::class, 'billing_setting_id');
    }

    public function hasSubscription(): bool
    {
        return in_array($this->billing_type, [self::BILLING_MONTHLY, self::BILLING_BOTH], true);
    }

    public function hasCommission(): bool
    {
        return in_array($this->billing_type, [self::BILLING_COMMISSION, self::BILLING_BOTH], true);
    }
}
