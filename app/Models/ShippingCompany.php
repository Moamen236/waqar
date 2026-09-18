<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ShippingCompany extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'delivery_fee',
        'return_fee',
        'status',
        'contact_person',
        'bank_name',
        'bank_account_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'decimal:2',
            'return_fee' => 'decimal:2',
        ];
    }

    public function statements(): HasMany
    {
        return $this->hasMany(ShippingCompanyStatement::class);
    }
}
