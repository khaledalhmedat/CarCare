<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $fillable = [
        'maintenance_request_id',
        'technician_id',
        'price',
        'estimated_days',
        'notes',
        'parts_included',
        'status',
        'accepted_at',
        'rejected_at',
        'rejection_reason',
        'viewed_at',
    ];

    protected $casts = [
        'parts_included' => 'boolean',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'viewed_at' => 'datetime',
        'estimated_days' => 'integer',
    ];

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }


    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }


    public function technicianProfile()
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'user_id');
    }


    public function serviceJob()
    {
        return $this->hasOne(ServiceJob::class, 'quotation_id');
    }
}
