<?php

namespace App\Models;

use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Promotion extends Model
{
    use HasTranslations, SerializesTranslations, SoftDeletes;

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'name',
        'description',
        'type',
        'discount_type',
        'discount_value',
        'starts_at',
        'ends_at',
        'priority',
        'stackable_with_coupons',
        'usage_limit',
        'usage_limit_per_customer',
        'times_used',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'stackable_with_coupons' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Bundle: every row a required component. Buy X Get Y: every row a
     * trigger, quantity = minimum "buy" amount (Question 17).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class);
    }

    /**
     * Only populated when type = buy_x_get_y.
     */
    public function rewards(): HasMany
    {
        return $this->hasMany(PromotionReward::class);
    }
}
