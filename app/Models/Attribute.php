<?php

namespace App\Models;

use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Attribute extends Model
{
    use HasTranslations, SerializesTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['name', 'sort_order'];

    /**
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
