<?php

namespace App\Reports\Returns;

use App\Enums\ReturnStage;
use App\Models\Employee;
use App\Models\OrderReturn;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * RET-01 · Returns Summary — return volume and cost by period.
 *
 * The at-delivery / post-delivery split is the column that matters: a
 * refusal at the door never left the warehouse and costs a delivery
 * attempt, while a post-delivery return costs the return leg and comes
 * back handled. Averaging them into one "returns" figure hides which
 * problem the business actually has.
 */
class ReturnsSummaryReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'returns.summary';
    }

    public function group(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return 'reports.returns.summary.title';
    }

    public function description(): string
    {
        return 'reports.returns.summary.description';
    }

    public function permission(): string
    {
        return 'reports.returns.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'granularity', 'compare_to',
            'return_stage', 'return_status', 'order_source', 'customer_id',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::date('bucket', 'reports.columns.period'),
            ReportColumn::number('returns_count', 'reports.columns.returns'),
            ReportColumn::number('at_delivery', 'reports.columns.at_delivery'),
            ReportColumn::number('post_delivery', 'reports.columns.post_delivery'),
            ReportColumn::number('units', 'reports.columns.units_returned'),
            ReportColumn::money('order_value', 'reports.columns.value_returned'),
            ReportColumn::money('refunded', 'reports.columns.refund_net'),
            ReportColumn::money('shipping_fees', 'reports.columns.return_fees'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['bucket', 'asc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $bucket = $this->bucketExpression($this->granularity($filters), 'returns.created_at');

        return $this->base($employee, $filters)
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw($this->aggregates())
            ->groupByRaw($bucket)
            ->orderByRaw('bucket asc');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        $row = $this->base($employee, $filters)->selectRaw($this->aggregates())->first();

        return $row === null ? [] : $this->figures($row);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OrderReturn>
     */
    private function base(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return OrderReturn::query()
            ->join('orders', 'orders.id', '=', 'returns.order_id')
            // OrderReturn::scopeVisibleTo inherits the order's visibility,
            // so this is scoped the same way every order listing is.
            ->whereIn('returns.order_id', $this->visibleOrders($employee))
            ->where('returns.created_at', '>=', $period['from'])
            ->where('returns.created_at', '<', $period['to'])
            ->when(! empty($filters['return_stage']), fn ($q) => $q->where('returns.stage', (string) $filters['return_stage']))
            ->when($this->listOf($filters, 'return_status'), fn ($q, array $v) => $q->whereIn('returns.status', $v))
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('returns.customer_id', (int) $filters['customer_id']))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['district_id']), fn ($q) => $q->where('orders.shipping_district_id', (int) $filters['district_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']));
    }

    private function aggregates(): string
    {
        $atDelivery = ReturnStage::AtDelivery->value;
        $postDelivery = ReturnStage::PostDelivery->value;

        // Units and value come from return_items joined to the original
        // line, so a partial return of two pieces out of five is valued at
        // two — not at the whole line.
        $units = '(SELECT COALESCE(SUM(ri.quantity), 0) FROM return_items ri WHERE ri.return_id = returns.id)';
        $value = '(SELECT COALESCE(SUM(ri.quantity * oi.unit_price), 0)
                   FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id
                   WHERE ri.return_id = returns.id)';
        $refund = "(SELECT COALESCE(SUM(rf.net_amount), 0) FROM refunds rf
                    WHERE rf.return_id = returns.id AND rf.status = 'completed')";

        return <<<SQL
            COUNT(*) as returns_count,
            SUM(CASE WHEN returns.stage = '{$atDelivery}' THEN 1 ELSE 0 END) as at_delivery,
            SUM(CASE WHEN returns.stage = '{$postDelivery}' THEN 1 ELSE 0 END) as post_delivery,
            COALESCE(SUM({$units}), 0) as units,
            COALESCE(SUM({$value}), 0) as order_value,
            COALESCE(SUM({$refund}), 0) as refunded,
            COALESCE(SUM(returns.return_shipping_fee), 0) as shipping_fees
        SQL;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return ['bucket' => $row->bucket] + $this->figures($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(object $row): array
    {
        return [
            'returns_count' => (int) $row->returns_count,
            'at_delivery' => (int) $row->at_delivery,
            'post_delivery' => (int) $row->post_delivery,
            'units' => (int) $row->units,
            'order_value' => (float) $row->order_value,
            'refunded' => (float) $row->refunded,
            'shipping_fees' => (float) $row->shipping_fees,
        ];
    }
}
