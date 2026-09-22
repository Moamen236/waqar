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
 * SAL-04 · Sales & Orders by Geography — where demand and money are.
 *
 * Deliberately one report rather than two. A separate "orders by area"
 * counting rows and a "revenue by area" summing money would be read side
 * by side every time, so they are one table with both, at whichever level
 * of the geo tree the user picks.
 *
 * The level is a filter (`geo_level`), not four reports: `orders` snapshots
 * all four geography FKs at checkout, so switching level is a change of
 * GROUP BY column and nothing else.
 */
class SalesByGeographyReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    /** @var array<string, array{column: string, table: string}> */
    private const LEVELS = [
        'governorate' => ['column' => 'shipping_governorate_id', 'table' => 'governorates'],
        'city' => ['column' => 'shipping_city_id', 'table' => 'cities'],
        'district' => ['column' => 'shipping_district_id', 'table' => 'districts'],
        'area' => ['column' => 'shipping_area_id', 'table' => 'areas'],
    ];

    public function key(): string
    {
        return 'sales.by-geography';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.byGeography.title';
    }

    public function description(): string
    {
        return 'reports.sales.byGeography.description';
    }

    public function permission(): string
    {
        return 'reports.sales.view';
    }

    public function defaultDateBasis(): string
    {
        return 'delivered';
    }

    public function availableDateBases(): array
    {
        return ['delivered', 'placed', 'collected'];
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
            'preset', 'date_from', 'date_to', 'date_basis', 'geo_level',
            'order_source', 'status', 'payment_status',
            'governorate_id', 'city_id', 'district_id', 'area_id',
            'representative_id', 'shipping_company_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('geography', 'reports.columns.geography'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::number('delivered', 'reports.columns.delivered'),
            ReportColumn::money('gross', 'reports.columns.gross_value'),
            ReportColumn::money('recognised', 'reports.columns.recognised'),
            ReportColumn::money('aov', 'reports.columns.aov'),
            ReportColumn::money('avg_shipping', 'reports.columns.avg_shipping'),
            ReportColumn::percent('success_rate', 'reports.columns.success_rate'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['recognised', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.net_of_shipping'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $level = (string) ($filters['geo_level'] ?? 'governorate');
        $level = isset(self::LEVELS[$level]) ? $level : 'governorate';
        ['column' => $column, 'table' => $table] = self::LEVELS[$level];

        $name = 'COALESCE('.$this->translatedName('geo.name').", '—')";

        $period = $this->resolvePeriod($filters);
        $basis = $this->dateBasis($filters, $this->defaultDateBasis());

        $query = Order::query()->visibleTo($employee);
        $query = $this->applyOrderDateBasis($query, $basis, $period);
        $query = $this->applyOrderFilters($query, $filters);

        $collected = $this->collectedSql();
        $sold = $this->soldStatusList();

        return $query
            ->leftJoin("{$table} as geo", 'geo.id', '=', "orders.{$column}")
            ->selectRaw("{$name} as geography")
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("SUM(CASE WHEN orders.status IN ({$sold}) THEN 1 ELSE 0 END) as delivered")
            ->selectRaw('COALESCE(SUM(orders.total), 0) as gross')
            ->selectRaw("COALESCE(SUM({$collected}), 0) as recognised")
            ->selectRaw('COALESCE(AVG(orders.shipping_amount), 0) as avg_shipping')
            ->groupByRaw("orders.{$column}, geography")
            ->orderByDesc('recognised');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $orders = (int) $row->orders_count;
        $delivered = (int) $row->delivered;
        $recognised = (float) $row->recognised;

        return [
            'geography' => $row->geography,
            'orders_count' => $orders,
            'delivered' => $delivered,
            'gross' => (float) $row->gross,
            'recognised' => $recognised,
            'aov' => $delivered === 0 ? null : round($recognised / $delivered, 2),
            'avg_shipping' => round((float) $row->avg_shipping, 2),
            'success_rate' => $this->ratio((float) $delivered, (float) $orders),
        ];
    }
}
