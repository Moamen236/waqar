<?php

namespace App\Models;

use App\Enums\CollectedMethod;
use App\Enums\CollectionType;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property PaymentStatus $status
 * @property CollectionType|null $collection_type
 * @property CollectedMethod|null $collected_method
 */
class Payment extends Model
{
    // Mirrors the DB defaults (cod / pending) on the in-memory model too,
    // so a freshly created instance reads correctly without a round-trip
    // — Eloquent doesn't otherwise reflect column defaults until refresh.
    protected $attributes = [
        'method' => 'cod',
        'status' => 'pending',
    ];

    protected $fillable = [
        'order_id',
        'method',
        'status',
        'collection_type',
        'collected_method',
        'amount',
        'collected_amount',
        'collected_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'collected_at' => 'datetime',
            'status' => PaymentStatus::class,
            'collection_type' => CollectionType::class,
            'collected_method' => CollectedMethod::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Every collection made against this payment, in order. A COD payment
     * settles in one go most of the time, but a courier who comes back
     * short leaves a balance that gets collected later
     * (ConfirmDeliveryResultAction::collectBalance) — each instalment is
     * its own treasury transaction, and together they are the history of
     * how this order got paid.
     *
     * @return MorphMany<TreasuryTransaction, $this>
     */
    public function transactions(): MorphMany
    {
        return $this->morphMany(TreasuryTransaction::class, 'reference')->oldest('id');
    }
}
