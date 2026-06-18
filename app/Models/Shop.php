<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = [
        'user_id', 'name', 'phone', 'city', 'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

  public function businessTypes()
{
    return $this->belongsToMany(BusinessType::class, 'shop_business_type');
}

public function carBrands()
{
    return $this->belongsToMany(CarBrand::class, 'shop_car_brand');
}

public function partCategories()
{
    return $this->belongsToMany(PartCategory::class, 'shop_part_category');
}
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}