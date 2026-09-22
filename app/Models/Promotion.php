<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Promotion extends Model
{
    use HasTranslations, RecordsActivity, SerializesTranslations, SoftDeletes;

    public array $translatable = ['name', 'description'];

    protected function activityLogName(): string
    {
        return 'catalog';
    }

    /**
     * `times_used` is deliberately omitted for the same reason as on
     * Coupon: it increments on every redemption, and an audit entry per
     * order would bury the edits that matter — the discount value, the
     * active window, the priority that decides which promotion wins.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'type',
            'discount_type',
            'discount_value',
            'starts_at',
            'ends_at',
            'priority',
            'stackable_with_coupons',
            'usage_limit',
            'usage_limit_per_customer',
            'is_active',
        ];
    }

    /**
     * Model-level defaults mirroring the migration's column defaults.
     *
     * Without these, a row created without passing them keeps `null` as its
     * *original* value while the cast reports `false`/`0`, so the attribute
     * is dirty on every subsequent save forever. That is invisible until
     * something watches for changes — and `RecordsActivity` does: every
     * `increment('times_used')` on a redemption was writing an audit entry
     * claiming `stackable_with_coupons` changed from null to false, which is
     * precisely the per-order noise `times_used` is excluded to avoid.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 0,
        'stackable_with_coupons' => false,
        'times_used' => 0,
        'is_active' => true,
    ];

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
