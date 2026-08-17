<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_PROCESSING = 'processing';
    const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'shop_id', 'total_price', 'status',
        'customer_latitude', 'customer_longitude', 'delivery_address_note', 'cancellation_reason'
    ];

    protected $casts = [
        'total_price' => 'decimal:2',
        'customer_latitude' => 'decimal:8',
        'customer_longitude' => 'decimal:8',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment()
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    public function getStatusTextAttribute()
    {
        return match($this->status) {
            'pending' => 'قيد الانتظار',
            'accepted' => 'تم القبول',
            'processing' => 'جاري التجهيز',
            'out_for_delivery' => 'خرج للتوصيل',
            'delivered' => 'تم التوصيل',
            'cancelled' => 'ملغي',
            default => $this->status,
        };
    }

    public function canCancel()
    {
        return in_array($this->status, ['pending', 'accepted']);
    }

    public function deliveryTrackingPoints()
{
    return $this->hasMany(DeliveryTrackingPoint::class);
}
}