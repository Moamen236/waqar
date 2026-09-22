<?php

namespace App\Reports\Finance;

use App\Enums\PaymentStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIN-06 · Outstanding Receivables — goods delivered, money not banked.
 *
 * The leak report. Every row is an order whose stock left the warehouse and
 * whose cash has not reached a treasury, aged from the delivery date so the
 * oldest sits at the top.
 *
 * Outstanding is measured against the **net** due (`payments.amount` less
 * the courier's shipping), never the gross — see FIN-02's docblock for why
 * that distinction is load-bearing rather than cosmetic.
 */
class OutstandingReceivablesReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'finance.receivables';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.receivables.title';
    }

    public function description(): string
    {
        return 'reports.finance.receivables.description';
    }

    public function permission(): string
    {
        return 'reports.finance.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'payment_status', 'representative_id', 'shipping_company_id',
            'governorate_id', 'city_id', 'area_id', 'order_source',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('order_number', 'reports.columns.order_number'),
            ReportColumn::date('delivered_at', 'reports.columns.delivered_at'),
            ReportColumn::text('customer', 'reports.columns.customer'),
            ReportColumn::text('carrier', 'reports.columns.carrier'),
            ReportColumn::money('net_due', 'reports.columns.net_due'),
            ReportColumn::money('collected', 'reports.columns.collected'),
            ReportColumn::money('outstanding', 'reports.columns.outstanding'),
            ReportColumn::enum('payment_status', 'reports.columns.payment_status', 'status.'),
            ReportColumn::number('days_outstanding', 'reports.columns.days_outstanding'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['days_outstanding', 'desc'];
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
        $collected = $this->collectedSql();
        $deliveredAt = "(SELECT MAX(h.created_at) FROM order_status_history h
                         WHERE h.order_id = orders.id AND h.to_status = 'Delivered')";

        // Gross owed less the courier's cut, floored at zero.
        $netDue = 'GREATEST(0, (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.order_id = orders.id)
                   - orders.shipping_amount)';

        return Order::query()
            ->visibleTo($employee)
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('delivery_representatives', 'delivery_representatives.id', '=', 'orders.delivery_representative_id')
            ->leftJoin('shipping_companies', 'shipping_companies.id', '=', 'orders.shipping_company_id')
            ->whereIn('orders.status', $this->soldStatuses())
            ->whereIn('orders.payment_status', [
                PaymentStatus::Pending->value,
                PaymentStatus::PartiallyCollected->value,
                PaymentStatus::NotCollected->value,
            ])
            ->when($this->listOf($filters, 'payment_status'), fn ($q, array $v) => $q->whereIn('orders.payment_status', $v))
            ->when($this->listOf($filters, 'representative_id'), fn ($q, array $v) => $q->whereIn('orders.delivery_representative_id', $v))
            ->when($this->listOf($filters, 'shipping_company_id'), fn ($q, array $v) => $q->whereIn('orders.shipping_company_id', $v))
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->selectRaw('orders.order_number as order_number')
            ->selectRaw("{$deliveredAt} as delivered_at")
            ->selectRaw('customers.name as customer')
            ->selectRaw('COALESCE(delivery_representatives.name, shipping_companies.name) as carrier')
            ->selectRaw("{$netDue} as net_due")
            ->selectRaw("{$collected} as collected")
            ->selectRaw('orders.payment_status as payment_status')
            ->havingRaw('(net_due - collected) > 0.009')
            ->orderByRaw("{$deliveredAt} ASC");
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $netDue = (float) $row->net_due;
        $collected = (float) $row->collected;

        return [
            'order_number' => $row->order_number,
            'delivered_at' => $row->delivered_at,
            'customer' => $row->customer,
            'carrier' => $row->carrier,
            'net_due' => $netDue,
            'collected' => $collected,
            'outstanding' => round($netDue - $collected, 2),
            'payment_status' => $row->payment_status instanceof \BackedEnum
                ? $row->payment_status->value
                : $row->payment_status,
            'days_outstanding' => $row->delivered_at === null
                ? null
                : now()->diffInDays(CarbonImmutable::parse((string) $row->delivered_at)),
        ];
    }
}
