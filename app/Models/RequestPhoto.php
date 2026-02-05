<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestPhoto extends Model
{
    protected $fillable = [
        'maintenance_request_id',
        'path'
    ];

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }
}
