<?php

namespace App\Reports\Concerns;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The standard filter bundle (spec A.3), applied to a query.
 *
 * Every report accepts some subset of the same keys, so the translation
 * from "what the filter bar sent" to "what the SQL says" lives here once
 * rather than being re-derived thirty-nine times with thirty-nine subtly
 * different ideas of what `date_to` includes.
 *
 * Two rules the whole module depends on:
 *
 * - **Date ranges are half-open predicates on the raw column** —
 *   `>= from AND < toExclusive`, never `WHERE DATE(created_at) = ?`.
 *   Wrapping the column in a function throws away the index the reporting
 *   migration just added, and does it silently: the report still returns
 *   the right rows, just by scanning the table. Bucketing happens in the
 *   SELECT, filtering on the bare column.
 * - **`date_basis` is explicit.** "Orders in September" reads
 *   `orders.created_at`; "revenue in September" reads the Delivered row in
 *   `order_status_history`, because `orders` has no `delivered_at` column
 *   and deliberately doesn't need one.
 */
trait AppliesStandardFilters
{
    /**
     * Resolve the reporting window from a preset or an explicit range.
     *
     * Returns `to` as the exclusive upper bound already — callers compare
     * `< to` and never have to remember whether the end date was inclusive.
     *
     * @param  array<string, mixed>  $filters
     * @return array{from: CarbonImmutable, to: CarbonImmutable, label_from: CarbonImmutable, label_to: CarbonImmutable}
     */
    protected function resolvePeriod(array $filters): array
    {
        $now = CarbonImmutable::now();
        $preset = (string) ($filters['preset'] ?? 'this_month');

        [$from, $inclusiveTo] = match ($preset) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'this_quarter' => [$now->startOfQuarter(), $now->endOfQuarter()],
            'last_quarter' => [$now->subQuarter()->startOfQuarter(), $now->subQuarter()->endOfQuarter()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()],
            default => $this->customPeriod($filters, $now),
        };

        return [
            'from' => $from,
            // Exclusive bound: the whole of the last day is included by
            // comparing `< next midnight`, which keeps the predicate on the
            // bare column instead of casting it to a date.
            'to' => $inclusiveTo->addSecond()->startOfSecond(),
            'label_from' => $from,
            'label_to' => $inclusiveTo,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function customPeriod(array $filters, CarbonImmutable $now): array
    {
        $from = ! empty($filters['date_from'])
            ? CarbonImmutable::parse((string) $filters['date_from'])->startOfDay()
            : $now->startOfMonth();

        $to = ! empty($filters['date_to'])
            ? CarbonImmutable::parse((string) $filters['date_to'])->endOfDay()
            : $now->endOfDay();

        // A reversed range is a typo, not an empty report — swapping is
        // what the user meant and costs nothing.
        return $from->greaterThan($to) ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }

    /**
     * The comparison window for `compare_to`.
     *
     * `previous_period` is the same *length* immediately before, not the
     * previous calendar month — comparing a 10-day range against a 31-day
     * one is the classic way to make a dashboard lie.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $period
     * @return array{from: CarbonImmutable, to: CarbonImmutable}|null
     */
    protected function comparisonPeriod(array $period, string $compareTo): ?array
    {
        if ($compareTo === 'previous_period') {
            $length = $period['from']->diffInSeconds($period['to']);

            return [
                'from' => $period['from']->subSeconds($length),
                'to' => $period['from'],
            ];
        }

        if ($compareTo === 'same_period_last_year') {
            return [
                'from' => $period['from']->subYear(),
                'to' => $period['to']->subYear(),
            ];
        }

        return null;
    }

    /**
     * Constrain an orders-rooted query to the period, on the basis the
     * report declared.
     *
     * @param  Builder<Order>  $query
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $period
     * @return Builder<Order>
     */
    protected function applyOrderDateBasis(Builder $query, string $basis, array $period): Builder
    {
        return match ($basis) {
            // `orders` has no delivered_at column by design — the status
            // history *is* that column, and it is indexed for this.
            'delivered' => $query->whereHas('statusHistory', fn ($history) => $history
                ->where('to_status', OrderStatus::Delivered->value)
                ->where('created_at', '>=', $period['from'])
                ->where('created_at', '<', $period['to'])),

            'collected' => $query->whereHas('payments', fn ($payment) => $payment
                ->whereNotNull('collected_at')
                ->where('collected_at', '>=', $period['from'])
                ->where('collected_at', '<', $period['to'])),

            default => $query
                ->where('orders.created_at', '>=', $period['from'])
                ->where('orders.created_at', '<', $period['to']),
        };
    }

    /**
     * The order-shaped filters, all optional, blank values ignored — the
     * same convention `Order::scopeFiltered()` already follows.
     *
     * @param  Builder<Order>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Order>
     */
    protected function applyOrderFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($this->listOf($filters, 'status'), fn (Builder $q, array $v) => $q->whereIn('orders.status', $v))
            ->when($this->listOf($filters, 'payment_status'), fn (Builder $q, array $v) => $q->whereIn('orders.payment_status', $v))
            ->when(! empty($filters['order_source']), fn (Builder $q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when($this->listOf($filters, 'employee_id'), fn (Builder $q, array $v) => $q->whereIn('orders.created_by_employee_id', $v))
            ->when(! empty($filters['customer_id']), fn (Builder $q) => $q->where('orders.customer_id', (int) $filters['customer_id']))
            ->when($this->term($filters, 'customer'), fn (Builder $q, string $v) => $q->whereHas('customer', fn ($c) => $c
                ->where('name', 'like', "%{$v}%")
                ->orWhere('phone', 'like', "%{$v}%")
                ->orWhere('email', 'like', "%{$v}%")))
            ->when(! empty($filters['governorate_id']), fn (Builder $q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn (Builder $q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['district_id']), fn (Builder $q) => $q->where('orders.shipping_district_id', (int) $filters['district_id']))
            ->when(! empty($filters['area_id']), fn (Builder $q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->when($this->listOf($filters, 'representative_id'), fn (Builder $q, array $v) => $q->whereIn('orders.delivery_representative_id', $v))
            ->when($this->listOf($filters, 'shipping_company_id'), fn (Builder $q, array $v) => $q->whereIn('orders.shipping_company_id', $v))
            ->when(! empty($filters['assignment_type']), fn (Builder $q) => $q->where('orders.delivery_assignment_type', (string) $filters['assignment_type']))
            ->when(! empty($filters['coupon_id']), fn (Builder $q) => $q->where('orders.coupon_id', (int) $filters['coupon_id']))
            ->when($this->listOf($filters, 'category_id'), fn (Builder $q, array $v) => $this->applyCatalogFilter($q, 'category_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn (Builder $q, array $v) => $this->applyCatalogFilter($q, 'product_id', $v))
            ->when($this->listOf($filters, 'variant_id'), fn (Builder $q, array $v) => $this->applyCatalogFilter($q, 'variant_id', $v))
            ->when($this->listOf($filters, 'warehouse_id'), fn (Builder $q, array $v) => $this->applyWarehouseFilter($q, $v));
    }

    /**
     * "Orders containing one of these products/categories/variants" — a
     * semi-join, so an order with three matching lines is still one order.
     *
     * @param  Builder<Order>  $query
     * @param  list<int>  $ids
     * @return Builder<Order>
     */
    protected function applyCatalogFilter(Builder $query, string $dimension, array $ids): Builder
    {
        return $query->whereHas('items', fn ($item) => match ($dimension) {
            'variant_id' => $item->whereIn('order_items.product_variant_id', $ids),
            'product_id' => $item->whereHas('productVariant', fn ($v) => $v->whereIn('product_id', $ids)),
            default => $item->whereHas('productVariant.product.categories', fn ($c) => $c->whereIn('categories.id', $ids)),
        });
    }

    /**
     * Warehouse attribution for an order.
     *
     * `orders` carries no warehouse column; the warehouse that fulfilled an
     * order is the one its stock was reserved from, which is exactly what
     * `InventoryService::warehouseForReservation()` reads in production.
     * The reservation movement is never deleted — a release or a sale
     * writes its own additional row — so this stays correct for cancelled
     * orders too, and is covered by `im_reference_index`.
     *
     * @param  Builder<Order>  $query
     * @param  list<int>  $warehouseIds
     * @return Builder<Order>
     */
    protected function applyWarehouseFilter(Builder $query, array $warehouseIds): Builder
    {
        return $query->whereExists(fn ($sub) => $sub
            ->selectRaw('1')
            ->from('inventory_movements')
            ->whereColumn('inventory_movements.reference_id', 'orders.id')
            ->where('inventory_movements.reference_type', (new Order)->getMorphClass())
            ->where('inventory_movements.type', InventoryMovementType::Reservation->value)
            ->whereIn('inventory_movements.warehouse_id', $warehouseIds));
    }

    /**
     * `orders.id` restricted to what this employee may see, as a sub-query.
     *
     * Reports rooted in a satellite table — `order_status_history`,
     * `delivery_assignments`, `payments` — still owe the same row scoping
     * as reports rooted in `orders`. Borrowing `Order::scopeVisibleTo()`
     * here keeps one definition of the rule: restating it per report is
     * precisely how a Customer Service agent ends up seeing another team's
     * orders through a side door.
     *
     * @return Builder<Order>
     */
    protected function visibleOrders(Employee $employee): Builder
    {
        return Order::query()->visibleTo($employee)->select('orders.id');
    }

    /**
     * A translatable JSON column, read at the active locale.
     *
     * Translatable names are `spatie/laravel-translatable` JSON columns
     * (CLAUDE.md), so selecting the raw column hands the reader a JSON blob.
     * Falling back to the fallback locale — rather than showing nothing —
     * matters for a catalogue that is authored Arabic-first but read by
     * both: a category with only an Arabic name still needs a label in the
     * English report.
     */
    protected function translatedName(string $column): string
    {
        $locale = app()->getLocale();
        $fallback = (string) config('app.fallback_locale', 'en');

        return "COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$locale}\"')),
            JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$fallback}\"'))
        )";
    }

    /**
     * The period bucket, as a raw SQL expression yielding a DATE.
     *
     * Always a date rather than a formatted label, so buckets sort
     * chronologically without a second sort key and the front end can
     * format them per locale — a hard requirement when the same report is
     * read in Arabic and English.
     */
    protected function bucketExpression(string $granularity, string $column): string
    {
        return match ($granularity) {
            'week' => "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)",
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'quarter' => "DATE_ADD(MAKEDATE(YEAR({$column}), 1), INTERVAL QUARTER({$column}) - 1 QUARTER)",
            'year' => "DATE_FORMAT({$column}, '%Y-01-01')",
            default => "DATE({$column})",
        };
    }

    protected function granularity(array $filters): string
    {
        $value = (string) ($filters['granularity'] ?? 'day');

        return in_array($value, ['day', 'week', 'month', 'quarter', 'year'], true) ? $value : 'day';
    }

    protected function dateBasis(array $filters, string $default): string
    {
        $value = (string) ($filters['date_basis'] ?? $default);

        return in_array($value, ['placed', 'delivered', 'collected'], true) ? $value : $default;
    }

    /**
     * Normalise a filter that may arrive as a scalar or a list, dropping
     * blanks. Returns null when nothing usable is left, so `when()` skips.
     *
     * @param  array<string, mixed>  $filters
     * @return list<int|string>|null
     */
    protected function listOf(array $filters, string $key): ?array
    {
        $value = $filters[$key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        $list = array_values(array_filter(
            is_array($value) ? $value : [$value],
            fn ($item) => $item !== null && $item !== '',
        ));

        return $list === [] ? null : $list;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function term(array $filters, string $key): ?string
    {
        $value = trim((string) ($filters[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * Guarded percentage: a zero denominator is "no data", not zero
     * percent, and definitely not a division error on a finance screen.
     */
    protected function ratio(float $numerator, float $denominator): ?float
    {
        return $denominator == 0.0 ? null : round($numerator / $denominator * 100, 2);
    }
}
