<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryRepresentativeArea extends Model
{
    protected $fillable = ['delivery_representative_id', 'geo_type', 'geo_id'];

    public function deliveryRepresentative(): BelongsTo
    {
        return $this->belongsTo(DeliveryRepresentative::class);
    }
}
