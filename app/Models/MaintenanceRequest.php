<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_ASSIGNED  = 'assigned';
    const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'description',
        'status'
    ];

    /* ================= Relations ================= */

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

    /* ================= Helpers ================= */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
