<?php

namespace App\Exports;

use App\Models\Employee;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The same rows /admin/orders shows — same visibleTo() scope (a Customer
 * Service Team Leader's export can never contain more than their own
 * team's orders) and the same filters, applied through Order::filtered()
 * so the download can never drift from the table — as an .xlsx download
 * instead of a paginated table.
 */
class OrdersExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  array<string, mixed>  $filters  Order::filtered() keys.
     */
    public function __construct(
        private readonly Employee $employee,
        private readonly array $filters = [],
    ) {}

    public function query(): Builder
    {
        return Order::query()
            ->visibleTo($this->employee)
            ->filtered($this->filters)
            ->with(['customer:id,name,phone', 'deliveryRepresentative:id,name', 'shippingCompany:id,name'])
            ->withCount('items')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Order #', 'Date', 'Customer', 'Phone', 'Items',
            'Subtotal', 'Discount', 'Shipping', 'Total',
            'Payment Status', 'Assigned To', 'Status',
        ];
    }

    /**
     * @return list<mixed>
     */
    public function map($order): array
    {
        $assignee = $order->deliveryRepresentative ?? $order->shippingCompany;

        return [
            $order->order_number,
            $order->created_at?->toDateTimeString(),
            $order->customer?->name,
            $order->customer?->phone,
            $order->items_count,
            $order->subtotal,
            $order->discount_amount,
            $order->shipping_amount,
            $order->total,
            $order->payment_status->value,
            $assignee?->name,
            $order->status->value,
        ];
    }
}
