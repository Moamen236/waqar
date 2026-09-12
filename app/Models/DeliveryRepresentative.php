<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryRepresentative extends Model
{
    protected $fillable = ['name', 'phone', 'status', 'notes'];

    public function areas(): HasMany
    {
        return $this->hasMany(DeliveryRepresentativeArea::class);
    }
}
