<?php

namespace App\Reports\Returns;

use App\Models\Employee;
use App\Models\ReturnItem;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * RET-02 · Return Reasons — why goods come back.
 *
 * Rooted in `return_items`, not `returns`: the item-level `reason_id` is
 * the specific one ("wrong size" for the trousers, "changed mind" for the
 * shirt in the same parcel), and rolling everything up to the header
 * reason would attribute both to whichever the customer picked first.
 *
 * Unlike ORD-04's free-text cancellation reasons, these are a real lookup
 * table (`return_reasons`), so grouping is exact and the label is
 * translatable.
 */
class ReturnReasonsReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'returns.reasons';
    }

    public function group(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return 'reports.returns.reasons.title';
    }

    public function description(): string
    {
        return 'reports.returns.reasons.description';
    }

    public function permission(): string
    {
        return 'reports.returns.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to',
            'return_stage', 'category_id', 'product_id', 'variant_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('reason', 'reports.columns.reason'),
            ReportColumn::number('returns_count', 'reports.columns.returns'),
            ReportColumn::number('units', 'reports.columns.units_returned'),
            ReportColumn::money('value', 'reports.columns.value_returned'),
            ReportColumn::text('top_product', 'reports.columns.top_product'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['units', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return ReturnItem::query()
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->join('return_reasons', 'return_reasons.id', '=', 'return_items.reason_id')
            ->join('product_variants', 'product_variants.id', '=', 'return_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('returns.order_id', $this->visibleOrders($employee))
            ->whereNull('returns.deleted_at')
            ->where('returns.created_at', '>=', $period['from'])
            ->where('returns.created_at', '<', $period['to'])
            ->when(! empty($filters['return_stage']), fn ($q) => $q->where('returns.stage', (string) $filters['return_stage']))
            ->when($this->listOf($filters, 'variant_id'), fn ($q, array $v) => $q->whereIn('return_items.product_variant_id', $v))
            ->when($this->listOf($filters, 'product_id'), fn ($q, array $v) => $q->whereIn('products.id', $v))
            ->when($this->listOf($filters, 'category_id'), fn ($q, array $v) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('product_categories')
                ->whereColumn('product_categories.product_id', 'products.id')
                ->whereIn('product_categories.category_id', $v)))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->selectRaw($this->translatedName('return_reasons.name').' as reason')
            ->selectRaw('COUNT(DISTINCT return_items.return_id) as returns_count')
            ->selectRaw('COALESCE(SUM(return_items.quantity), 0) as units')
            ->selectRaw('COALESCE(SUM(return_items.quantity * order_items.unit_price), 0) as value')
            // The product most often behind this reason. MAX over a
            // concatenation of count+name is the standard single-pass way
            // to pick an argmax in MySQL without a second query per row.
            ->selectRaw('SUBSTRING_INDEX(MAX(CONCAT(LPAD(return_items.quantity, 10, "0"), "|", order_items.product_name_snapshot)), "|", -1) as top_product')
            ->groupBy('return_reasons.id', 'reason')
            ->orderByDesc('units');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'reason' => $row->reason,
            'returns_count' => (int) $row->returns_count,
            'units' => (int) $row->units,
            'value' => (float) $row->value,
            'top_product' => $row->top_product,
        ];
    }
}
