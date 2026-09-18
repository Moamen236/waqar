<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryRepresentative extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'phone', 'status', 'notes'];

    public function areas(): HasMany
    {
        return $this->hasMany(DeliveryRepresentativeArea::class);
    }
}
