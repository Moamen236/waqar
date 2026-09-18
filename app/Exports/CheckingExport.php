<?php

namespace App\Exports;

use App\Enums\OrderStatus;
use App\Models\Employee;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Checking's own work queue (New/Checking/Postponed/Backorder) as an
 * .xlsx download — same visibleTo() scope as CheckingController::index().
 */
class CheckingExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(private readonly Employee $employee) {}

    public function query(): Builder
    {
        return Order::query()
            ->visibleTo($this->employee)
            ->whereIn('status', [OrderStatus::New, OrderStatus::Checking, OrderStatus::Postponed, OrderStatus::Backorder])
            ->with('customer:id,name,phone')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Order #', 'Customer', 'Phone', 'Status', 'Total', 'Placed At'];
    }

    /**
     * @return list<mixed>
     */
    public function map($order): array
    {
        return [
            $order->order_number,
            $order->customer?->name,
            $order->customer?->phone,
            $order->status->value,
            $order->total,
            $order->created_at?->toDateTimeString(),
        ];
    }
}
