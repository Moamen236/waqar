<?php

namespace App\Models;

use App\Enums\TreasuryTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TreasuryTransactionType $type
 */
class TreasuryTransaction extends Model
{
    protected $fillable = [
        'treasury_id',
        'type',
        'amount',
        'created_by',
        'reference_type',
        'reference_id',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'type' => TreasuryTransactionType::class,
        ];
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
