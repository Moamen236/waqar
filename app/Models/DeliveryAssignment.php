<?php

namespace App\Models;

use App\Enums\DeliveryAssignmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DeliveryAssignmentType $assignment_type
 */
class DeliveryAssignment extends Model
{
    protected $fillable = [
        'order_id',
        'assignment_type',
        'delivery_representative_id',
        'shipping_company_id',
        'assigned_by',
        'assigned_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'assignment_type' => DeliveryAssignmentType::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryRepresentative(): BelongsTo
    {
        return $this->belongsTo(DeliveryRepresentative::class);
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }
}
