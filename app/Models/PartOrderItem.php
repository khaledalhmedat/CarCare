<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartOrderItem extends Model
{
    protected $fillable = [
        'part_order_id',
        'part_id',
        'quantity',
        'price'
    ];

    public function order()
    {
        return $this->belongsTo(PartOrder::class, 'part_order_id');
    }

    public function part()
    {
        return $this->belongsTo(Part::class);
    }

    public function installationRequest()
    {
        return $this->hasOne(InstallationRequest::class);
    }
}
