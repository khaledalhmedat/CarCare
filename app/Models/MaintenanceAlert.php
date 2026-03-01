<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceAlert extends Model
{
    const TYPE_KM   = 'km';
    const TYPE_TIME = 'time';

    protected $fillable = [
        'vehicle_id',
        'type',
        'value',
        'is_active'
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
