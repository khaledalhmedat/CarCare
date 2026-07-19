<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarWasher extends Model
{
    protected $fillable = [
        'user_id',
        'shop_name',
        'logo',
        'phone',
        'city',
        'address',
        'description',
        'latitude',
        'longitude',
        'services',
        'prices',
        'service_prices',
        'working_hours',
        'is_available',
        'is_verified',
        'average_rating',
        'ratings_count',
        'status',
        'rejection_reason',
        'approved_at',
        'rejected_at',
        'suspended_at',
    ];

    protected $casts = [
        'services' => 'array',
        'prices' => 'array',
        'service_prices' => 'array',
        'working_hours' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_available' => 'boolean',
        'is_verified' => 'boolean',
        'average_rating' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bookings()
    {
        return $this->hasMany(CarwashBooking::class);
    }

    public function ratings()
    {
        return $this->hasMany(CarWashRating::class);
    }
}