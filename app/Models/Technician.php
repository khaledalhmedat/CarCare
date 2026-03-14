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
        'certifications' => 'array',
        'is_available' => 'boolean',
        'average_rating' => 'float',
    ];



    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceJobs()
    {
        return $this->hasMany(ServiceJob::class);
    }

    public function ratings()
    {
        return $this->hasManyThrough(
            Rating::class,
            ServiceJob::class,
            'technician_id',
            'service_job_id',
            'user_id',
            'id'
        );
    }

    public function getAverageRatingAttribute()
    {
        return $this->ratings()->avg('rating') ?? 0;
    }

    public function getRatingsCountAttribute()
    {
        return $this->ratings()->count();
    }
}
