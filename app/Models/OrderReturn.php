<?php

namespace App\Models;

use App\Enums\ReturnStage;
use App\Enums\ReturnStatus;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Table is "returns" (spec Section 24) — the class is named OrderReturn
 * since `Return` is a reserved PHP keyword. Unifies the at_delivery and
 * post_delivery return workflows (Section 12) under one `stage` field.
 *
 * @property ReturnStage $stage
 * @property ReturnStatus $status
 * @property-read Collection<int, ReturnItem> $items
 */
class OrderReturn extends Model
{
    use RecordsActivity, SoftDeletes;

    protected function activityLogName(): string
    {
        return 'orders';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'order_id',
            'customer_id',
            'stage',
            'status',
            'reason_id',
            'return_shipping_fee',
        ];
    }

    protected $table = 'returns';

    protected $fillable = [
        'order_id',
        'customer_id',
        'stage',
        'status',
        'reason_id',
        'return_shipping_fee',
        'customer_accepted_return_shipping_fee_at',
        'customer_notes',
    ];

    // Requested is the natural starting status of a new return.
    protected $attributes = [
        'status' => 'requested',
    ];

    protected function casts(): array
    {
        return [
            'return_shipping_fee' => 'decimal:2',
            'customer_accepted_return_shipping_fee_at' => 'datetime',
            'stage' => ReturnStage::class,
            'status' => ReturnStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(ReturnReason::class, 'reason_id');
    }

    /**
     * @return HasMany<ReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class, 'return_id');
    }

    public function activitySubjectLabel(): string
    {
        // withTrashed: the order is soft-deletable, so the plain relation
        // goes null exactly when someone most needs the label to still say
        // which order this return belonged to.
        $orderNumber = Order::withTrashed()->whereKey($this->order_id)->value('order_number');

        return is_string($orderNumber) ? $orderNumber : 'Return #'.$this->getKey();
    }
}
