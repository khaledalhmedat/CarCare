<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserDeviceRegistration extends Model
{
    protected $fillable = [
        'user_id',
        'fcm_token',
        'platform',
        'device_id',
        'is_active',
        'failed_count',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
