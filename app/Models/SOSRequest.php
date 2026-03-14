<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SosRequest extends Model
{
    const STATUS_OPEN     = 'open';
    const STATUS_ASSIGNED = 'assigned';
    const STATUS_CLOSED   = 'closed';

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'lat',
        'lng',
        'status'
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trackingSession()
    {
        return $this->hasOne(LiveTrackingSession::class);
    }
}
