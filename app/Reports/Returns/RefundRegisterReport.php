<?php

namespace App\Reports\Returns;

use App\Models\Employee;
use App\Models\Refund;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * RET-04 · Refunds Register & Liability — every refund, and what is still
 * owed to customers.
 *
 * `net_amount` is read from the table, never recomputed: the split between
 * the gross refund and the return shipping fee the customer bore is a
 * decision the refund workflow already made and stored, and a report that
 * re-derived it could disagree with the money that actually moved.
 *
 * Pending refunds are a real liability — money the business owes and has
 * not yet sent — so they sort to the top rather than being filtered out as
 * "not done yet".
 */
class RefundRegisterReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'returns.refunds';
    }

    public function group(): string
    {
        return 'returns';
    }

    public function title(): string
    {
        return 'reports.returns.refunds.title';
    }

    public function description(): string
    {
        return 'reports.returns.refunds.description';
    }

    public function permission(): string
    {
        return 'reports.returns.view';
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to',
            'refund_status', 'refund_method', 'employee_id', 'customer_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::datetime('created_at', 'reports.columns.requested_at'),
            ReportColumn::text('order_number', 'reports.columns.order_number'),
            ReportColumn::text('customer', 'reports.columns.customer'),
            ReportColumn::money('amount', 'reports.columns.refund_gross'),
            ReportColumn::money('return_shipping_fee', 'reports.columns.return_fees'),
            ReportColumn::money('net_amount', 'reports.columns.refund_net'),
            ReportColumn::enum('method', 'reports.columns.refund_method', 'reports.refundMethod.'),
            ReportColumn::enum('status', 'reports.columns.status', 'status.'),
            ReportColumn::text('processed_by', 'reports.columns.processed_by'),
            ReportColumn::datetime('processed_at', 'reports.columns.processed_at'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['created_at', 'desc'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);

        return Refund::query()
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->leftJoin('employees', 'employees.id', '=', 'refunds.processed_by')
            ->whereIn('refunds.order_id', $this->visibleOrders($employee))
            ->where('refunds.created_at', '>=', $period['from'])
            ->where('refunds.created_at', '<', $period['to'])
            ->when(! empty($filters['refund_status']), fn ($q) => $q->where('refunds.status', (string) $filters['refund_status']))
            ->when(! empty($filters['refund_method']), fn ($q) => $q->where('refunds.method', (string) $filters['refund_method']))
            ->when($this->listOf($filters, 'employee_id'), fn ($q, array $v) => $q->whereIn('refunds.processed_by', $v))
            ->when(! empty($filters['customer_id']), fn ($q) => $q->where('orders.customer_id', (int) $filters['customer_id']))
            ->selectRaw('refunds.created_at as created_at')
            ->selectRaw('orders.order_number as order_number')
            ->selectRaw('customers.name as customer')
            ->selectRaw('refunds.amount as amount')
            ->selectRaw('refunds.return_shipping_fee as return_shipping_fee')
            ->selectRaw('refunds.net_amount as net_amount')
            ->selectRaw('refunds.method as method')
            ->selectRaw('refunds.status as status')
            ->selectRaw('employees.full_name as processed_by')
            ->selectRaw('refunds.processed_at as processed_at')
            // Pending first: an unpaid refund is a liability and an action,
            // a completed one is history.
            ->orderByRaw("FIELD(refunds.status, 'pending') DESC")
            ->orderByDesc('refunds.created_at');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(Employee $employee, array $filters): array
    {
        // DB::query() rather than Refund::query(): the aggregate row is a
        // set of sums, not a refund, and hydrating a model would apply that
        // model's casts and soft-delete scope to a derived table.
        $row = DB::query()
            ->fromSub($this->query($employee, $filters)->reorder(), 'r')
            ->selectRaw('COALESCE(SUM(r.amount), 0) as amount')
            ->selectRaw('COALESCE(SUM(r.return_shipping_fee), 0) as return_shipping_fee')
            ->selectRaw('COALESCE(SUM(r.net_amount), 0) as net_amount')
            ->first();

        return $row === null ? [] : [
            'amount' => (float) $row->amount,
            'return_shipping_fee' => (float) $row->return_shipping_fee,
            'net_amount' => (float) $row->net_amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        return [
            'created_at' => $row->created_at,
            'order_number' => $row->order_number,
            'customer' => $row->customer,
            'amount' => (float) $row->amount,
            'return_shipping_fee' => (float) $row->return_shipping_fee,
            'net_amount' => (float) $row->net_amount,
            'method' => $row->method instanceof \BackedEnum ? $row->method->value : $row->method,
            'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            'processed_by' => $row->processed_by,
            'processed_at' => $row->processed_at,
        ];
    }
}
