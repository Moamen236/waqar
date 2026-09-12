<?php

namespace App\Models;

use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class District extends Model
{
    use HasTranslations, SerializesTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['city_id', 'name', 'status'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }
}
