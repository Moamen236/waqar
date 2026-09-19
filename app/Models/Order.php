<?php

namespace App\Models;

use App\Enums\CustomerOrderStatus;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\RecordsActivity;
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
            'order_number',
            'customer_id',
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
        ];
    }

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
     * Customer Service data scoping (Section 15, Question 16), two tiers:
     *
     * - A **Team Leader** sees their own team's CS-sourced orders — their
     *   agents' and their own.
     * - A plain **Customer Service** agent sees only the CS-sourced orders
     *   they created themselves.
     * - **Store Orders** sees every storefront order, and nothing else —
     *   the role that exists because the agent tier below leaves website
     *   orders unattended.
     *
     * Every other role stays unscoped. The Dashboard (Question 18) reads
     * its order widgets through this same scope, so both tiers' totals
     * match the rows the listing screens show them.
     *
     * Note: narrowing the individual agent is a deliberate divergence from
     * Q16 as written ("nothing above narrows an individual agent's
     * visibility") — the business asked for per-agent scoping after the
     * spec was resolved. An agent therefore no longer sees storefront
     * orders at all, only their own phone orders.
     */
    public function scopeVisibleTo(Builder $query, Employee $employee): Builder
    {
        // Checked before the agent role: a leader normally holds both, and
        // the wider team scope is the one that should win.
        if ($employee->hasRole('Customer Service Team Leader')) {
            $teamIds = $employee->teamMembers()->pluck('id')->push($employee->id);

            return $query->where('order_source', OrderSource::CustomerService->value)
                ->whereIn('created_by_employee_id', $teamIds);
        }

        if ($employee->hasRole('Customer Service')) {
            return $query->where('order_source', OrderSource::CustomerService->value)
                ->where('created_by_employee_id', $employee->id);
        }

        // Store Orders is the mirror image of the agent tier: every
        // website order, and only those. Together the two tiers cover the
        // whole book without overlapping.
        if ($employee->hasRole('Store Orders')) {
            return $query->where('order_source', OrderSource::Website->value);
        }

        return $query;
    }

    /**
     * Shared listing filters for /admin/orders and its .xlsx export — one
     * scope so the table and the download can never disagree on what
     * "filtered" means. Every entry is optional; blank values are ignored.
     *
     * Supported keys: status, q (order no. / shipping phone / customer
     * name+phone), customer (customer name/phone/email), governorate_id,
     * city_id, district_id, area_id (shipping destination),
     * representative_id, shipping_company_id (who carries it), date_from /
     * date_to (placed on, inclusive), qty_min / qty_max (total product
     * pieces in the order).
     *
     * @param  array<string, mixed>  $filters
     */
    public function scopeFiltered(Builder $query, array $filters): Builder
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['q'] ?? ''));
        $customer = trim((string) ($filters['customer'] ?? ''));

        $qtyMin = $filters['qty_min'] ?? null;
        $qtyMin = $qtyMin === '' || $qtyMin === null ? null : (int) $qtyMin;
        $qtyMax = $filters['qty_max'] ?? null;
        $qtyMax = $qtyMax === '' || $qtyMax === null ? null : (int) $qtyMax;

        // Total pieces per order, computed live — no join, so the listing
        // query needs no GROUP BY and pagination counts stay exact.
        $itemsTable = (new OrderItem)->getTable();
        $quantitySql = "(SELECT COALESCE(SUM(quantity), 0) FROM {$itemsTable} WHERE {$itemsTable}.order_id = orders.id)";

        return $query
            ->when($status !== '', fn (Builder $inner) => $inner->where('status', $status))
            ->when($search !== '', fn (Builder $inner) => $inner->where(
                fn (Builder $term) => $term
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shipping_phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $candidate) => $candidate
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
            ))
            ->when($customer !== '', fn (Builder $inner) => $inner->whereHas('customer', fn (Builder $candidate) => $candidate
                ->where('name', 'like', "%{$customer}%")
                ->orWhere('phone', 'like', "%{$customer}%")
                ->orWhere('email', 'like', "%{$customer}%")))
            ->when(! empty($filters['governorate_id']), fn (Builder $inner) => $inner->where('shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn (Builder $inner) => $inner->where('shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['district_id']), fn (Builder $inner) => $inner->where('shipping_district_id', (int) $filters['district_id']))
            ->when(! empty($filters['area_id']), fn (Builder $inner) => $inner->where('shipping_area_id', (int) $filters['area_id']))
            ->when(! empty($filters['representative_id']), fn (Builder $inner) => $inner->where('delivery_representative_id', (int) $filters['representative_id']))
            ->when(! empty($filters['shipping_company_id']), fn (Builder $inner) => $inner->where('shipping_company_id', (int) $filters['shipping_company_id']))
            ->when(! empty($filters['date_from']), fn (Builder $inner) => $inner->whereDate('created_at', '>=', (string) $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $inner) => $inner->whereDate('created_at', '<=', (string) $filters['date_to']))
            ->when($qtyMin !== null, fn (Builder $inner) => $inner->whereRaw("{$quantitySql} >= ?", [$qtyMin]))
            ->when($qtyMax !== null, fn (Builder $inner) => $inner->whereRaw("{$quantitySql} <= ?", [$qtyMax]));
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
