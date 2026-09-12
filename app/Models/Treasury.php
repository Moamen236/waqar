<?php

namespace App\Models;

use App\Enums\TreasuryType;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property TreasuryType $type
 */
class Treasury extends Model
{
    use RecordsActivity, SoftDeletes;

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
            'name',
            'type',
            'account_number',
            'current_balance',
            'is_active',
        ];
    }

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = ['name', 'type', 'account_number', 'current_balance', 'is_active'];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'type' => TreasuryType::class,
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class);
    }
}
