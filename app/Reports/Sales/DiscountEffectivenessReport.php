<?php

namespace App\Reports\Sales;

use App\Models\Employee;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * SAL-05 · Coupons & Discounts — what the discounting is buying.
 *
 * Grouped by coupon, with the un-couponed orders gathered into one row so
 * the discounted and undiscounted halves of the book can be compared
 * directly — a coupon report that only lists coupons cannot answer "is the
 * discount earning its keep".
 *
 * Promotion attribution is deliberately out of scope here: promotions
 * attach at the *line* (`order_items.promotion_id`), and mixing a
 * line-level dimension into an order-level table would double-count any
 * order whose lines carry different promotions. Their redemption counts
 * live on `promotions.times_used`.
 */
class DiscountEffectivenessReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'sales.discounts';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.discounts.title';
    }

    public function description(): string
    {
        return 'reports.sales.discounts.description';
    }

    public function permission(): string
    {
        return 'reports.sales.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    public function availableDateBases(): array
    {
        return ['placed', 'delivered'];
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'date_basis', 'compare_to',
            'order_source', 'coupon_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('coupon', 'reports.columns.coupon'),
            ReportColumn::text('type', 'reports.columns.discount_type'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('gross', 'reports.columns.gross_value'),
            ReportColumn::money('discount', 'reports.columns.discount_given'),
            ReportColumn::percent('discount_rate', 'reports.columns.discount_rate'),
            ReportColumn::money('aov', 'reports.columns.aov'),
            ReportColumn::number('redemptions_left', 'reports.columns.redemptions_left'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['discount', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $basis = $this->dateBasis($filters, 'placed');

        $query = Order::query()->visibleTo($employee);
        $query = $this->applyOrderDateBasis($query, $basis, $period);
        $query = $this->applyOrderFilters($query, $filters);

        $units = $this->unitsSql();

        return $query
            ->leftJoin('coupons', 'coupons.id', '=', 'orders.coupon_id')
            ->selectRaw("COALESCE(coupons.code, '—') as coupon")
            ->selectRaw("COALESCE(coupons.type, '—') as type")
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM({$units}), 0) as units")
            ->selectRaw('COALESCE(SUM(orders.total), 0) as gross')
            ->selectRaw('COALESCE(SUM(orders.discount_amount), 0) as discount')
            // Null for an unlimited coupon, and for the no-coupon row —
            // rendered as an em dash rather than a misleading zero.
            ->selectRaw('MAX(coupons.usage_limit) - MAX(coupons.times_used) as redemptions_left')
            ->groupBy('orders.coupon_id', 'coupon', 'type')
            ->orderByDesc('discount');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $orders = (int) $row->orders_count;
        $gross = (float) $row->gross;
        $discount = (float) $row->discount;

        return [
            'coupon' => $row->coupon,
            'type' => $row->type,
            'orders_count' => $orders,
            'units' => (int) $row->units,
            'gross' => $gross,
            'discount' => $discount,
            // Against gross + discount: the discount's share of what the
            // order would have been, not of what it became.
            'discount_rate' => $this->ratio($discount, $gross + $discount),
            'aov' => $orders === 0 ? null : round($gross / $orders, 2),
            'redemptions_left' => $row->redemptions_left === null ? null : (int) $row->redemptions_left,
        ];
    }
}
