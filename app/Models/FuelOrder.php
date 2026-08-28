<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelOrder extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'fuel_provider_id',
        'fuel_type',
        'amount',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'city',
        'current_radius_km',
        'radius_stage_started_at',
        'total_price',
        'status',
        'scheduled_time',
        'accepted_at',
        'started_at',
        'completed_at',
        'cancellation_reason',
        'notes',
        'estimated_arrival_minutes',
        'provider_notes',
    ];

    protected $casts = [
        'scheduled_time' => 'datetime',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'radius_stage_started_at' => 'datetime',
        'delivery_latitude' => 'decimal:7',
        'delivery_longitude' => 'decimal:7',
        'amount' => 'float',
        'total_price' => 'decimal:2',
        'estimated_arrival_minutes' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function fuelProvider()
    {
        return $this->belongsTo(FuelProvider::class);
    }

    public function fuelLog()
    {
        return $this->hasOne(FuelLog::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACCEPTED]);
    }

    public function trackingPoints()
    {
        return $this->hasMany(FuelOrderTrackingPoint::class);
    }
}
