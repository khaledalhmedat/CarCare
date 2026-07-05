<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryTrackingPoint extends Model
{
    protected $fillable = [
        'order_id',
        'shop_id',
        'latitude',
        'longitude',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }
}