<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Part extends Model
{
    protected $fillable = [
        'name',
        'price',
        'stock'
    ];

    public function orderItems()
    {
        return $this->hasMany(PartOrderItem::class);
    }

    public function isAvailable(int $qty = 1): bool
    {
        return $this->stock >= $qty;
    }
}
