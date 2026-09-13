<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingRate extends Model
{
    use SoftDeletes;

    protected $fillable = ['geo_type', 'geo_id', 'price', 'free_shipping_threshold', 'is_active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'free_shipping_threshold' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
