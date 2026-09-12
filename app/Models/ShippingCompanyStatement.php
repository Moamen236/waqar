<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingCompanyStatement extends Model
{
    protected $fillable = [
        'shipping_company_id',
        'period_start',
        'period_end',
        'delivered_orders_count',
        'expected_customer_collection',
        'delivery_fees_owed',
        'return_fees_owed',
        'net_amount_expected',
        'transferred_amount',
        'outstanding_amount',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'expected_customer_collection' => 'decimal:2',
            'delivery_fees_owed' => 'decimal:2',
            'return_fees_owed' => 'decimal:2',
            'net_amount_expected' => 'decimal:2',
            'transferred_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
        ];
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
