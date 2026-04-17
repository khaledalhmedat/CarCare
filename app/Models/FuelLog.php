<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelLog extends Model
{
    protected $fillable = [
        'vehicle_id',
        'fuel_order_id',
        'amount',
        'fuel_type',
        'fuel_provider_id',
        'cost',
        'km_at_fill',
        'odometer_image',
    ];

    protected $casts = [
        'amount' => 'float',
        'cost' => 'float',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function fuelOrder()
    {
        return $this->belongsTo(FuelOrder::class);
    }

    public function fuelProvider()
    {
        return $this->belongsTo(FuelProvider::class);
    }
}