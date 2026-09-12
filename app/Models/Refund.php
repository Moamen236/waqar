<?php

namespace App\Models;

use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RefundMethod $method
 * @property RefundStatus $status
 */
class Refund extends Model
{
    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'return_id',
        'order_id',
        'amount',
        'return_shipping_fee',
        'net_amount',
        'method',
        'status',
        'reference_number',
        'processed_by',
        'processed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'return_shipping_fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'processed_at' => 'datetime',
            'method' => RefundMethod::class,
            'status' => RefundStatus::class,
        ];
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'processed_by');
    }
}
