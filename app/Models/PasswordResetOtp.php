<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetOtp extends Model
{
    protected $fillable = [
        'email',
        'otp_hash',
        'reset_token_hash',
        'expires_at',
        'reset_token_expires_at',
        'verified_at',
        'used_at',
        'attempts_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'reset_token_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'used_at' => 'datetime',
        'attempts_count' => 'integer',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isResetTokenExpired(): bool
    {
        return $this->reset_token_expires_at === null || $this->reset_token_expires_at->isPast();
    }
}
