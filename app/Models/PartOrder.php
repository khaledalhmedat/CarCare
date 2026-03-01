<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartOrder extends Model
{
    const STATUS_PENDING   = 'pending';
    const STATUS_PAID      = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'total',
        'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(PartOrderItem::class);
    }

    public function payment()
    {
        return $this->morphOne(Payment::class, 'payable');
    }
}
