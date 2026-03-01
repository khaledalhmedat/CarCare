<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone',
        'status'
    ];

    protected static function booted()
    {
        static::creating(function ($tenant) {
            $tenant->uuid = Str::uuid();
        });
    }

    /* ================= Relations ================= */

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /* ================= Scopes ================= */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
