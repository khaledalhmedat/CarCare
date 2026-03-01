<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelLog extends Model
{
    protected $fillable = [
        'vehicle_id',
        'amount',
        'cost',
        'km_at_fill'
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
