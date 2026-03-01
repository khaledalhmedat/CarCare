<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarwashBooking extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_ASSIGNED  = 'assigned';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'technician_id',
        'scheduled_at',
        'status'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
