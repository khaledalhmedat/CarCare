<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_PAID    = 'paid';
    const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'amount',
        'method',
        'status'
    ];

    public function payable()
    {
        return $this->morphTo();
    }
}
