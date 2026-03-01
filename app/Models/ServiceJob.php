<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceJob extends Model
{
    const STATUS_ASSIGNED   = 'assigned';
    const STATUS_INPROGRESS = 'in_progress';
    const STATUS_COMPLETED  = 'completed';

    protected $fillable = [
        'maintenance_request_id',
        'technician_id',
        'status'
    ];

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function maintenanceRecord()
    {
        return $this->hasOne(MaintenanceRecord::class);
    }
}
