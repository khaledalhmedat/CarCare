<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallationRequest extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_ASSIGNED  = 'assigned';
    const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'part_order_item_id',
        'technician_id',
        'status'
    ];

    public function orderItem()
    {
        return $this->belongsTo(PartOrderItem::class, 'part_order_item_id');
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
