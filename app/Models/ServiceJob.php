<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceJob extends Model
{
    protected $fillable = [
        'maintenance_request_id',
        'quotation_id',
        'technician_id',
        'status',
        'scheduled_date',
        'notes',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
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
