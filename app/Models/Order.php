<?php

namespace App\Models;

use App\Enums\CustomerOrderStatus;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property OrderStatus $status
 * @property CustomerOrderStatus $customer_status
 * @property PaymentStatus $payment_status
 * @property OrderSource $order_source
 * @property DeliveryAssignmentType|null $delivery_assignment_type
 * @property-read Collection<int, OrderItem> $items
 */
class Order extends Model
{
    use SoftDeletes;

    // Mirrors the DB defaults so a freshly created instance reads
    // correctly without a round-trip (Eloquent doesn't otherwise reflect
    // column defaults until refresh).
    protected $attributes = [
        'status' => 'New',
        'payment_status' => 'pending',
    ];

    protected $fillable = [
        'order_number',
        'customer_id',
        'created_by_employee_id',
        'order_source',
        'status',
        'customer_status',
        'payment_status',
        'delivery_assignment_type',
        'delivery_representative_id',
        'shipping_company_id',
        'subtotal',
        'discount_amount',
        'shipping_amount',
        'total',
        'coupon_id',
        'shipping_recipient_name',
        'shipping_phone',
        'shipping_governorate_id',
        'shipping_city_id',
        'shipping_district_id',
        'shipping_area_id',
        'shipping_address_line',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'order_source' => OrderSource::class,
            'status' => OrderStatus::class,
            'customer_status' => CustomerOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'delivery_assignment_type' => DeliveryAssignmentType::class,
        ];
    }

    protected static function booted(): void
    {
        // Customer-facing order_number, seeded at 1001, independent of id
        // (Section 10) — assigned here rather than a DB auto-increment so
        // it stays under application control regardless of which code
        // path creates the order (storefront checkout or Customer
        // Service's /admin/orders/create, Section 03).
        static::creating(function (Order $order) {
            if (empty($order->order_number)) {
                $order->order_number = (static::max('order_number') ?? 1000) + 1;
            }
        });
    }

    /**
     * Customer Service Team Leader sees only their own team's CS-sourced
     * orders (Section 15, Question 16); every other role is unscoped —
     * same rule the Dashboard Index (Question 18) applies to its widgets,
     * reused here for any order-listing screen (Phase 4).
     */
    public function scopeVisibleTo(Builder $query, Employee $employee): Builder
    {
        if (! $employee->hasRole('Customer Service Team Leader')) {
            return $query;
        }

        $teamIds = $employee->teamMembers()->pluck('id')->push($employee->id);

        return $query->where('order_source', OrderSource::CustomerService->value)
            ->whereIn('created_by_employee_id', $teamIds);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdByEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    public function deliveryRepresentative(): BelongsTo
    {
        return $this->belongsTo(DeliveryRepresentative::class);
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<Governorate, $this>
     */
    public function shippingGovernorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class, 'shipping_governorate_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function shippingCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'shipping_city_id');
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function shippingDistrict(): BelongsTo
    {
        return $this->belongsTo(District::class, 'shipping_district_id');
    }

    /**
     * @return BelongsTo<Area, $this>
     */
    public function shippingArea(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'shipping_area_id');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * @return HasMany<DeliveryAssignment, $this>
     */
    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<OrderReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }
}
