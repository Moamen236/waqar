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
 * team's orders) and the same status/search filters — as an .xlsx
 * download instead of a paginated table.
 */
class OrdersExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly Employee $employee,
        private readonly string $status = '',
        private readonly string $search = '',
    ) {}

    public function query(): Builder
    {
        return Order::query()
            ->visibleTo($this->employee)
            ->with(['customer:id,name,phone', 'deliveryRepresentative:id,name', 'shippingCompany:id,name'])
            ->withCount('items')
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('order_number', 'like', "%{$this->search}%")
                    ->orWhere('shipping_phone', 'like', "%{$this->search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', "%{$this->search}%")
                        ->orWhere('phone', 'like', "%{$this->search}%"))
            ))
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
