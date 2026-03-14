<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    protected $fillable = [
        'user_id',
        'vehicle_id',
        'description',
        'priority',
        'preferred_date',
        'status',
        'cancellation_reason',
        'cancelled_at',
        'accepted_quotation_id',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'cancelled_at' => 'datetime',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function photos()
    {
        return $this->hasMany(RequestPhoto::class);
    }

    public function quotations()
    {
        return $this->hasMany(Quotation::class);
    }

    public function serviceJob()
    {
        return $this->hasOne(ServiceJob::class);
    }


    public function maintenanceRecord()
    {
        return $this->hasOneThrough(
            MaintenanceRecord::class,
            ServiceJob::class,
            'maintenance_request_id',
            'service_job_id',
            'id',
            'id'
        );
    }


    public function maintenanceRecordSimple()
    {
        return $this->hasOne(MaintenanceRecord::class, 'maintenance_request_id');
    }
}
