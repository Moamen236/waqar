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
 * SAL-07 · Customer Sales & Retention — who buys, how often, how much.
 *
 * Paginated, unlike most of the sales group: this one has a row per
 * customer and is read as a ranked list rather than a summary, so it grows
 * with the customer base rather than with the period.
 *
 * "First order" is computed across the customer's whole history, not just
 * the reporting window — otherwise every customer looks new in a report
 * that starts on the first of the month.
 */
class CustomerSalesReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'sales.customers';
    }

    public function group(): string
    {
        return 'sales';
    }

    public function title(): string
    {
        return 'reports.sales.customers.title';
    }

    public function description(): string
    {
        return 'reports.sales.customers.description';
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
        return ['delivered', 'placed'];
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'date_basis',
            'order_source', 'customer_id', 'customer',
            'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('customer', 'reports.columns.customer'),
            ReportColumn::text('phone', 'reports.columns.phone'),
            ReportColumn::enum('customer_type', 'reports.columns.customer_type', 'reports.customerType.'),
            ReportColumn::date('first_order', 'reports.columns.first_order'),
            ReportColumn::date('last_order', 'reports.columns.last_order'),
            ReportColumn::number('orders_count', 'reports.columns.orders'),
            ReportColumn::number('units', 'reports.columns.units'),
            ReportColumn::money('recognised', 'reports.columns.net_revenue'),
            ReportColumn::money('aov', 'reports.columns.aov'),
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
        $period = $this->resolvePeriod($filters);
        $basis = $this->dateBasis($filters, $this->defaultDateBasis());

        $query = Order::query()
            ->visibleTo($employee)
            ->whereIn('orders.status', $this->soldStatuses());

        $query = $this->applyOrderDateBasis($query, $basis, $period);
        $query = $this->applyOrderFilters($query, $filters);

        $collected = $this->collectedSql();
        $refunded = $this->refundedSql();
        $units = $this->unitsSql();

        return $query
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw('customers.name as customer')
            ->selectRaw('customers.phone as phone')
            ->selectRaw('customers.is_guest as is_guest')
            // Lifetime bounds, deliberately not restricted to the window:
            // "first order" inside a one-month filter would make every
            // customer look new.
            ->selectRaw('(SELECT MIN(o2.created_at) FROM orders o2 WHERE o2.customer_id = customers.id AND o2.deleted_at IS NULL) as first_order')
            ->selectRaw('(SELECT MAX(o2.created_at) FROM orders o2 WHERE o2.customer_id = customers.id AND o2.deleted_at IS NULL) as last_order')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw("COALESCE(SUM({$units}), 0) as units")
            ->selectRaw("COALESCE(SUM({$collected}) - SUM({$refunded}), 0) as recognised")
            ->groupBy('customers.id', 'customers.name', 'customers.phone', 'customers.is_guest')
            ->orderByDesc('recognised');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $orders = (int) $row->orders_count;
        $recognised = (float) $row->recognised;

        return [
            'customer' => $row->customer,
            'phone' => $row->phone,
            'customer_type' => $row->is_guest ? 'guest' : 'registered',
            'first_order' => $row->first_order,
            'last_order' => $row->last_order,
            'orders_count' => $orders,
            'units' => (int) $row->units,
            'recognised' => $recognised,
            'aov' => $orders === 0 ? null : round($recognised / $orders, 2),
        ];
    }
}
