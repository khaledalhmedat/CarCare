<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingPoint extends Model
{
    protected $fillable = [
        'live_tracking_session_id',
        'lat',
        'lng'
    ];

    public function session()
    {
        return $this->belongsTo(LiveTrackingSession::class, 'live_tracking_session_id');
    }
}

