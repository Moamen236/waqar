<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryRepresentativeArea extends Model
{
    use SoftDeletes;

    protected $fillable = ['delivery_representative_id', 'geo_type', 'geo_id'];

    public function deliveryRepresentative(): BelongsTo
    {
        return $this->belongsTo(DeliveryRepresentative::class);
    }
}
