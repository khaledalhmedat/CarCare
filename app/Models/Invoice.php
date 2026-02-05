<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'subscription_id',
        'amount',
        'issued_at'
    ];

    protected $casts = [
        'issued_at' => 'date'
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
