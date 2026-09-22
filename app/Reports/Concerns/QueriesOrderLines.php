<?php

namespace App\Reports\Concerns;

use App\Models\Employee;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared root for reports that measure *lines* rather than orders —
 * sales by product, by category, and gross margin.
 *
 * Line-level reports value a line at `order_items.subtotal`, not at a share
 * of what was collected: there is no way to allocate one payment across
 * several lines, and inventing an allocation would make the numbers look
 * precise while being made up. The header-level reports (SAL-01, FIN-*)
 * are where banked cash is the measure.
 *
 * Units returned are subtracted where asked for, from `return_items`, so a
 * product that ships a hundred and takes back forty does not read as a
 * hundred sold.
 */
trait QueriesOrderLines
{
    use AppliesStandardFilters, MeasuresSales;

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OrderItem>
     */
    protected function lineBase(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $basis = $this->dateBasis($filters, 'delivered');

        $query = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('orders.id', $this->visibleOrders($employee))
            ->whereIn('orders.status', $this->soldStatuses());

        $query = $this->applyLineDateBasis($query, $basis, $period);

        return $query
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['district_id']), fn ($q) => $q->where('orders.shipping_district_id', (int) $filters['district_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('order_items.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->when($this->listOf($filters, 'category_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $v)))
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('inventory_movements')
                ->whereColumn('inventory_movements.reference_id', 'orders.id')
                ->where('inventory_movements.reference_type', Order::class)
                ->where('inventory_movements.type', 'reservation')
                ->whereIn('inventory_movements.warehouse_id', $v)));
    }

    /**
     * @param  Builder<OrderItem>  $query
     * @param  array{from: CarbonImmutable, to: CarbonImmutable}  $period
     * @return Builder<OrderItem>
     */
    protected function applyLineDateBasis(Builder $query, string $basis, array $period): Builder
    {
        return match ($basis) {
            'delivered' => $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('order_status_history as h')
                ->whereColumn('h.order_id', 'orders.id')
                ->where('h.to_status', 'Delivered')
                ->where('h.created_at', '>=', $period['from'])
                ->where('h.created_at', '<', $period['to'])),

            'collected' => $query->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('payments as p')
                ->whereColumn('p.order_id', 'orders.id')
                ->whereNotNull('p.collected_at')
                ->where('p.collected_at', '>=', $period['from'])
                ->where('p.collected_at', '<', $period['to'])),

            default => $query
                ->where('orders.created_at', '>=', $period['from'])
                ->where('orders.created_at', '<', $period['to']),
        };
    }

    /**
     * Units returned against a line, from `return_items`.
     *
     * Correlated on `order_item_id`, which is what `return_items` actually
     * stores — so a partial return of two out of five pieces subtracts two,
     * not the whole line.
     */
    protected function returnedUnitsSql(): string
    {
        return '(SELECT COALESCE(SUM(ri.quantity), 0) FROM return_items ri
                 WHERE ri.order_item_id = order_items.id)';
    }
}
