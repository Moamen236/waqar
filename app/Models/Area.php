<?php

namespace App\Models;

use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class Area extends Model
{
    use HasTranslations, SerializesTranslations;

    public array $translatable = ['name'];

    protected $fillable = ['city_id', 'district_id', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Optional — District is not mandatory (Section 24, confirmation
     * #12). city_id stays as the fallback when this is null.
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
