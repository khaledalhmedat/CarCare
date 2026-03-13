<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Technician extends Model
{

    protected $fillable = [
        'user_id',
        'specialization',
        'experience_years',
        'phone',
        'city',
        'hourly_rate',
        'is_available',
        'certifications',
    ];

    protected $casts = [
        'certifications' => 'array'
    ];

   

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class);
    }
}
