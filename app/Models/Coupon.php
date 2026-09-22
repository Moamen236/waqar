<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Coupon extends Model
{
    use RecordsActivity;

    protected function activityLogName(): string
    {
        return 'catalog';
    }

    /**
     * Note what is *absent*: `times_used`. It increments on every
     * redemption, so logging it would write an audit entry per order and
     * bury the deliberate edits this trail exists to surface — a coupon's
     * value being changed, its window moved, or it being switched back on
     * after expiry. Redemption volume is a sales question, and SAL-05
     * already answers it from `orders.coupon_id`.
     *
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'code',
            'type',
            'value',
            'minimum_order_amount',
            'usage_limit',
            'usage_limit_per_user',
            'starts_at',
            'ends_at',
            'is_active',
        ];
    }

    /**
     * `code` rather than a name — it is what an operator recognises a
     * coupon by, and the label has to stay readable after the row is gone.
     */
    public function activitySubjectLabel(): string
    {
        return (string) ($this->code ?: 'Coupon #'.$this->getKey());
    }

    /**
     * Mirrors the migration's column defaults — see the same block on
     * Promotion for why a missing default makes an attribute permanently
     * dirty and turns every redemption into a spurious audit entry.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'times_used' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'code',
        'type',
        'value',
        'minimum_order_amount',
        'usage_limit',
        'usage_limit_per_user',
        'times_used',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'coupon_categories');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'coupon_collections');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'coupon_customers');
    }
}
