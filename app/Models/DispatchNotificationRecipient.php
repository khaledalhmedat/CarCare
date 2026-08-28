<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchNotificationRecipient extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_type',
        'request_id',
        'recipient_type',
        'recipient_id',
        'notified_at',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
    ];
}
