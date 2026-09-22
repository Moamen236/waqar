<?php

namespace App\Reports\Finance;

use App\Models\Employee;
use App\Models\Payment;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\Concerns\MeasuresSales;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIN-02 · COD Cash Collection — cash banked against cash owed.
 *
 * **The shortfall column is the one that must not be got wrong.**
 * `payments.amount` is gross (what the customer handed over, kept gross for
 * the invoice); `payments.collected_amount` is net of the shipping the
 * courier kept at the door. Comparing the two directly reports every
 * correctly settled order as short by exactly its shipping — the original
 * Phase C bug. The net due is `amount - orders.shipping_amount`, mirroring
 * `Order::netOfShipping()`, and that is what shortfall measures against.
 *
 * "Posted by" is the accountant on the treasury transaction that references
 * the payment. `payments` has no `collected_by` column, but
 * `ConfirmDeliveryResultAction` writes the payment update and the treasury
 * transaction inside one `DB::transaction`, so one cannot exist without the
 * other — this is exact attribution, not a guess.
 */
class CashCollectionReport extends ReportDefinition
{
    use AppliesStandardFilters, MeasuresSales;

    public function key(): string
    {
        return 'finance.collections';
    }

    public function group(): string
    {
        return 'finance';
    }

    public function title(): string
    {
        return 'reports.finance.collections.title';
    }

    public function description(): string
    {
        return 'reports.finance.collections.description';
    }

    public function permission(): string
    {
        return 'reports.finance.view';
    }

    public function defaultDateBasis(): string
    {
        return 'collected';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to',
            'payment_status', 'employee_id', 'treasury_id',
            'representative_id', 'shipping_company_id',
            'governorate_id', 'city_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::datetime('collected_at', 'reports.columns.collected_at'),
            ReportColumn::text('order_number', 'reports.columns.order_number'),
            ReportColumn::text('customer', 'reports.columns.customer'),
            ReportColumn::text('carrier', 'reports.columns.carrier'),
            ReportColumn::money('customer_paid', 'reports.columns.customer_paid'),
            ReportColumn::money('courier_fee', 'reports.columns.courier_fee'),
            ReportColumn::money('net_due', 'reports.columns.net_due'),
            ReportColumn::money('collected', 'reports.columns.collected'),
            ReportColumn::money('shortfall', 'reports.columns.shortfall'),
            ReportColumn::enum('status', 'reports.columns.payment_status', 'status.'),
            ReportColumn::text('treasury', 'reports.columns.treasury'),
            ReportColumn::text('posted_by', 'reports.columns.posted_by'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['collected_at', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.net_of_shipping', 'reports.notes.phase_c_boundary'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $paymentClass = (new Payment)->getMorphClass();

        return Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('delivery_representatives', 'delivery_representatives.id', '=', 'orders.delivery_representative_id')
            ->leftJoin('shipping_companies', 'shipping_companies.id', '=', 'orders.shipping_company_id')
            // The treasury transaction that banked this payment carries the
            // accountant and the account — see the class docblock.
            ->leftJoin('treasury_transactions', function ($join) use ($paymentClass) {
                $join->on('treasury_transactions.reference_id', '=', 'payments.id')
                    ->where('treasury_transactions.reference_type', '=', $paymentClass);
            })
            ->leftJoin('treasuries', 'treasuries.id', '=', 'treasury_transactions.treasury_id')
            ->leftJoin('employees', 'employees.id', '=', 'treasury_transactions.created_by')
            ->whereIn('payments.order_id', $this->visibleOrders($employee))
            ->whereNotNull('payments.collected_at')
            ->where('payments.collected_at', '>=', $period['from'])
            ->where('payments.collected_at', '<', $period['to'])
            ->when($this->listOf($filters, 'payment_status'), fn ($q, array $v) => $q->whereIn('payments.status', $v))
            ->when($this->listOf($filters, 'treasury_id'), fn ($q, array $v) => $q->whereIn('treasury_transactions.treasury_id', $v))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('treasury_transactions.created_by', $v))
            ->when($this->listOf($filters, 'representative_id'), fn ($q, array $v) => $q->whereIn('orders.delivery_representative_id', $v))
            ->when($this->listOf($filters, 'shipping_company_id'), fn ($q, array $v) => $q->whereIn('orders.shipping_company_id', $v))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->selectRaw('payments.collected_at as collected_at')
            ->selectRaw('orders.order_number as order_number')
            ->selectRaw('customers.name as customer')
            ->selectRaw('COALESCE(delivery_representatives.name, shipping_companies.name) as carrier')
            ->selectRaw('payments.amount as customer_paid')
            ->selectRaw('orders.shipping_amount as courier_fee')
            ->selectRaw('payments.collected_amount as collected')
            ->selectRaw('payments.status as status')
            ->selectRaw('treasuries.name as treasury')
            ->selectRaw('employees.full_name as posted_by')
            ->orderByDesc('payments.collected_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $paid = (float) $row->customer_paid;
        $fee = (float) $row->courier_fee;
        $collected = (float) ($row->collected ?? 0);
        $netDue = round(max(0, $paid - $fee), 2);

        return [
            'collected_at' => $row->collected_at,
            'order_number' => $row->order_number,
            'customer' => $row->customer,
            'carrier' => $row->carrier,
            'customer_paid' => $paid,
            'courier_fee' => $fee,
            'net_due' => $netDue,
            'collected' => $collected,
            'shortfall' => round(max(0, $netDue - $collected), 2),
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'treasury' => $row->treasury,
            'posted_by' => $row->posted_by,
        ];
    }
}
