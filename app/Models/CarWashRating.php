<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarWashRating extends Model
{
    protected $fillable = [
        'user_id',
        'carwash_booking_id',
        'car_washer_id',
        'rating',
        'review',
    ];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(CarwashBooking::class, 'carwash_booking_id');
    }

    public function carWasher()
    {
        return $this->belongsTo(CarWasher::class);
    }
}