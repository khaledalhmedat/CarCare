<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartCategory extends Model
{
    protected $fillable = ['name'];

    public function shops()
    {
        return $this->belongsToMany(Shop::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}