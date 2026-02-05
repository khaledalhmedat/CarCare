<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveTrackingSession extends Model
{
    protected $fillable = [
        'sos_request_id',
        'technician_id'
    ];

    public function sosRequest()
    {
        return $this->belongsTo(SosRequest::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function trackingPoints()
    {
        return $this->hasMany(TrackingPoint::class);
    }
}
