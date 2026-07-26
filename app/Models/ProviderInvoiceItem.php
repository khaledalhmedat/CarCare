<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderInvoiceItem extends Model
{
    public const TYPE_SUBSCRIPTION = 'monthly_subscription';
    public const TYPE_COMMISSION = 'commission';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_FREE_TRIAL = 'free_trial';

    protected $fillable = [
        'provider_invoice_id',
        'item_type',
        'source_type',
        'source_id',
        'description',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(ProviderInvoice::class, 'provider_invoice_id');
    }
}
