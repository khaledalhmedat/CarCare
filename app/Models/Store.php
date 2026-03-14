<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Store extends Model
{
    protected $fillable = [
        'user_id',
        'store_name',
        'commercial_register',
        'tax_number',
        'phone',
        'address',
        'city',
        'latitude',
        'longitude',
        'logo',
        'description',
        'is_verified',
        'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parts()
    {
        return $this->hasMany(Part::class);
    }
}
