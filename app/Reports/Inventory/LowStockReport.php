<?php

namespace App\Reports\Inventory;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\WarehouseInventory;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * INV-02 · Low & Out of Stock — the reorder worklist.
 *
 * The threshold is `config('inventory.low_stock_threshold')`, the **same
 * value `InventoryService::flagLowStock()` already uses** to notify the
 * Warehouse Manager. Reading the config rather than inventing a number
 * here is what stops the report and the alerts disagreeing about what
 * "low" means — a filter override is offered for what-if analysis, but the
 * default is the system's own line.
 *
 * `warehouse_inventory` has no reorder-point column, so days-of-cover is
 * offered alongside as a velocity-aware second opinion: twenty units is
 * comfortable for a slow SKU and an emergency for a fast one.
 */
class LowStockReport extends ReportDefinition
{
    use AppliesStandardFilters;

    /** Sales window used to derive velocity, in days. */
    private const VELOCITY_DAYS = 30;

    public function key(): string
    {
        return 'inventory.low-stock';
    }

    public function group(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return 'reports.inventory.lowStock.title';
    }

    public function description(): string
    {
        return 'reports.inventory.lowStock.description';
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
        return ['warehouse_id', 'category_id', 'product_id', 'variant_id', 'threshold'];
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
            ReportColumn::number('available', 'reports.columns.available'),
            ReportColumn::number('reserved', 'reports.columns.reserved'),
            ReportColumn::number('sold_30d', 'reports.columns.sold_30d'),
            ReportColumn::number('days_cover', 'reports.columns.days_cover'),
            ReportColumn::enum('stock_state', 'reports.columns.stock_state', 'reports.stockState.'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['available', 'asc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.low_stock_threshold'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $threshold = $this->threshold($filters);
        $sold = OrderStatus::Delivered->value;
        $partial = OrderStatus::PartiallyReturned->value;
        $since = now()->subDays(self::VELOCITY_DAYS)->toDateTimeString();

        $available = '(warehouse_inventory.quantity - warehouse_inventory.reserved_quantity)';

        // Units delivered in the velocity window, for this variant. Scoped
        // to sold statuses for the same reason revenue is: an order that
        // was placed but never delivered consumed no stock.
        $sold30 = "(SELECT COALESCE(SUM(oi.quantity), 0)
                    FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_variant_id = warehouse_inventory.product_variant_id
                      AND o.deleted_at IS NULL
                      AND o.status IN ('{$sold}', '{$partial}')
                      AND o.created_at >= '{$since}')";

        return WarehouseInventory::query()
            ->join('warehouses', 'warehouses.id', '=', 'warehouse_inventory.warehouse_id')
            ->join('product_variants', 'product_variants.id', '=', 'warehouse_inventory.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->whereRaw("{$available} <= ?", [$threshold])
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
            ->selectRaw("{$available} as available")
            ->selectRaw('warehouse_inventory.reserved_quantity as reserved')
            ->selectRaw("{$sold30} as sold_30d")
            ->orderByRaw("{$available} asc");
    }

    private function threshold(array $filters): int
    {
        $override = $filters['threshold'] ?? null;

        if ($override !== null && $override !== '' && (int) $override >= 0) {
            return (int) $override;
        }

        return (int) config('inventory.low_stock_threshold', 5);
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $available = (int) $row->available;
        $sold = (int) $row->sold_30d;
        $velocity = $sold / self::VELOCITY_DAYS;

        return [
            'warehouse' => $row->warehouse,
            'sku' => $row->sku,
            'product' => $row->product,
            'available' => $available,
            'reserved' => (int) $row->reserved,
            'sold_30d' => $sold,
            // Null rather than infinity for a SKU with no recent sales:
            // "never runs out" and "sells nothing" are the same arithmetic
            // and very different situations, so the cell stays empty and
            // the reader looks at the sold column instead.
            'days_cover' => $velocity > 0 ? round($available / $velocity, 1) : null,
            'stock_state' => $available <= 0 ? 'out' : 'low',
        ];
    }
}
