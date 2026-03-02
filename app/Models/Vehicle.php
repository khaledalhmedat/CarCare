<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'brand',
        'model',
        'year',
        'plate_number',
        'current_km',
        'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function maintenanceRecords()
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function fuelLogs()
    {
        return $this->hasMany(FuelLog::class);
    }

    public function maintenanceAlerts()
    {
        return $this->hasMany(MaintenanceAlert::class);
    }

    public function needsMaintenance(): bool
    {
        return $this->maintenanceAlerts()
            ->where('is_active', true)
            ->exists();
    }

    
}