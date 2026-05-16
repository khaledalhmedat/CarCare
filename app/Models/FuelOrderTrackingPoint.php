<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelOrderTrackingPoint extends Model
{
    protected $fillable = [
        'fuel_order_id',
        'fuel_provider_id',
        'latitude',
        'longitude',
    ];

    public function fuelOrder()
    {
        return $this->belongsTo(FuelOrder::class);
    }

    public function fuelProvider()
    {
        return $this->belongsTo(FuelProvider::class);
    }
}
