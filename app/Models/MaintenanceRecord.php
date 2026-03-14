<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRecord extends Model
{
    protected $fillable = [
        'service_job_id',
        'vehicle_id',
        'maintenance_request_id',
        'details',
        'parts_used',
        'completion_notes',
        'recommendations',
        'completed_at',
    ];

    protected $casts = [
        'parts_used' => 'array',
        'completed_at' => 'datetime',
    ];

    public function serviceJob()
    {
        return $this->belongsTo(ServiceJob::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }
}
