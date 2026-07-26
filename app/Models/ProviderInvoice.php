<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderInvoice extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    // 'overdue' is NOT a stored status — it is derived from status=issued + due_at past.

    protected $fillable = [
        'invoice_number',
        'provider_type',
        'provider_id',
        'billing_setting_id',
        'period_start',
        'period_end',
        'issued_at',
        'due_at',
        'subtotal',
        'commission_total',
        'subscription_total',
        'total_amount',
        'status',
        'external_payment_method',
        'external_payment_reference',
        'paid_at',
        'confirmed_by',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'commission_total' => 'decimal:2',
        'subscription_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(ProviderInvoiceItem::class);
    }

    public function billingSetting()
    {
        return $this->belongsTo(ProviderBillingSetting::class, 'billing_setting_id');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Overdue is computed, never stored: an issued invoice whose due date has passed
     * and which has not been paid or cancelled.
     */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ISSUED
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    public function effectiveStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    public function isUnpaid(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ISSUED], true);
    }
}
