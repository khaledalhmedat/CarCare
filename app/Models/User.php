<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'tenant_id',
        'name',
        'email',
        'password',
        'phone',
        'status'
    ];

    protected $hidden = [
        'password',
        'remember_token'
    ];


    protected static function booted()
    {
        static::creating(fn($user) => $user->uuid = Str::uuid());
    }

    /* ================= Relations ================= */

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }

    public function maintenanceRequests()
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    /* ================= RBAC ================= */

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas(
                'permissions',
                fn($q) =>
                $q->where('slug', $permission)
            )->exists();
    }

    /* ================= Scopes ================= */

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
