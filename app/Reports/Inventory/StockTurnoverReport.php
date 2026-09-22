<?php

namespace App\Reports\Inventory;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\WarehouseInventory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * INV-05 · Stock Turnover & Dead Stock — capital tied up in things that
 * are not moving.
 *
 * Distinct from SAL-02, which ranks SKUs by revenue: this ranks them by
 * velocity *relative to the stock held*. A product can be a top seller and
 * still be over-stocked, and a slow seller with two units on the shelf is
 * nobody's problem. Sorting by days-since-last-sale puts the money that is
 * sitting still at the top.
 */
class StockTurnoverReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'inventory.turnover';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'reports.inventory.turnover.title';
    }

    public function description(): string
    {
        return 'reports.inventory.turnover.description';
    }

    public function permission(): string
    {
        return 'reports.inventory.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return ['preset', 'date_from', 'date_to', 'warehouse_id', 'category_id', 'product_id', 'variant_id'];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('warehouse', 'reports.columns.warehouse'),
            ReportColumn::text('sku', 'reports.columns.sku'),
            ReportColumn::text('product', 'reports.columns.product'),
            ReportColumn::number('on_hand', 'reports.columns.on_hand'),
            ReportColumn::money('cost_value', 'reports.columns.cost_value', true, 'reports.cost.view'),
            ReportColumn::number('units_sold', 'reports.columns.units_sold'),
            ReportColumn::number('turnover', 'reports.columns.turnover'),
            ReportColumn::date('last_sold', 'reports.columns.last_sold'),
            ReportColumn::number('days_since_sale', 'reports.columns.days_since_sale'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['days_since_sale', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $cost = $this->unitCostSql();
        $sold = OrderStatus::Delivered->value;
        $partial = OrderStatus::PartiallyReturned->value;

        $from = $period['from']->toDateTimeString();
        $to = $period['to']->toDateTimeString();

        $unitsSold = "(SELECT COALESCE(SUM(oi.quantity), 0)
                       FROM order_items oi JOIN orders o ON o.id = oi.order_id
                       WHERE oi.product_variant_id = warehouse_inventory.product_variant_id
                         AND o.deleted_at IS NULL
                         AND o.status IN ('{$sold}', '{$partial}')
                         AND o.created_at >= '{$from}' AND o.created_at < '{$to}')";

        // Lifetime, not windowed — "last sold" inside a one-month filter
        // would report every dormant SKU as never having sold at all.
        $lastSold = "(SELECT MAX(o.created_at)
                      FROM order_items oi JOIN orders o ON o.id = oi.order_id
                      WHERE oi.product_variant_id = warehouse_inventory.product_variant_id
                        AND o.deleted_at IS NULL
                        AND o.status IN ('{$sold}', '{$partial}'))";

        return WarehouseInventory::query()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_inventory.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_inventory.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->when($this->listOf($filters, 'warehouse_id'), fn ($q, array $v) => $q->whereIn('warehouse_inventory.warehouse_id', $v))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('warehouse_inventory.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->when($this->listOf($filters, 'category_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $v)))
            ->selectRaw('warehouses.name as warehouse')
            ->selectRaw('product_variants.sku as sku')
            ->selectRaw($this->translatedName('products.name').' as product')
            ->selectRaw('warehouse_inventory.quantity as on_hand')
            ->selectRaw("(warehouse_inventory.quantity * {$cost}) as cost_value")
            ->selectRaw("{$unitsSold} as units_sold")
            ->selectRaw("{$lastSold} as last_sold")
            ->orderByRaw("{$lastSold} IS NULL DESC, {$lastSold} ASC");
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $onHand = (int) $row->on_hand;
        $soldUnits = (int) $row->units_sold;

        return [
            'warehouse' => $row->warehouse,
            'sku' => $row->sku,
            'product' => $row->product,
            'on_hand' => $onHand,
            'cost_value' => (float) $row->cost_value,
            'units_sold' => $soldUnits,
            // Turnover against stock held. Null when there is no stock —
            // dividing by zero would rank an empty shelf as infinitely
            // efficient, which is the opposite of useful.
            'turnover' => $onHand > 0 ? round($soldUnits / $onHand, 2) : null,
            'last_sold' => $row->last_sold,
            'days_since_sale' => $row->last_sold === null
                ? null
                : now()->diffInDays(CarbonImmutable::parse((string) $row->last_sold)),
        ];
    }
}
