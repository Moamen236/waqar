<?php

namespace App\Models;

use App\Enums\TreasuryTransactionType;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TreasuryTransactionType $type
 */
/**
 * @property-read Treasury $treasury
 */
class TreasuryTransaction extends Model
{
    use RecordsActivity;

    protected function activityLogName(): string
    {
        return 'treasury';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'treasury_id',
            'type',
            'amount',
            'reference_type',
            'reference_id',
            'description',
        ];
    }

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

    public function activitySubjectLabel(): string
    {
        // withTrashed — see OrderReturn::activitySubjectLabel().
        $name = Treasury::withTrashed()->whereKey($this->treasury_id)->value('name');

        return (is_string($name) ? $name : 'Treasury #'.$this->treasury_id).' '.$this->amount;
    }
}
