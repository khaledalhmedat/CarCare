<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FuelProvider extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'company_name',
        'phone',
        'city',
        'address',
        'latitude',
        'longitude',
        'fuel_types',
        'prices',
        'is_available',
        'is_verified',
    ];

    protected $casts = [
        'fuel_types' => 'array',
        'prices' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_available' => 'boolean',
        'is_verified' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fuelOrders()
    {
        return $this->hasMany(FuelOrder::class);
    }
}