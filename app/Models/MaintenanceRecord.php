<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRecord extends Model
{
    protected $fillable = [
        'service_job_id',
        'vehicle_id',
        'details'
    ];

    public function serviceJob()
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
