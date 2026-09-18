<?php

namespace App\Exports;

use App\Models\Employee;
use App\Models\OrderReturn;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The same rows /admin/returns shows — same optional status filter — as
 * an .xlsx download instead of a paginated table.
 */
class ReturnsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly string $status = '',
        private readonly ?Employee $employee = null,
    ) {}

    public function query(): Builder
    {
        return OrderReturn::query()
            // Same Customer Service scoping the screen applies, so a
            // download can never be wider than the list it came from.
            ->when($this->employee !== null, fn ($query) => $query->visibleTo($this->employee))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->with(['order:id,order_number,total', 'customer:id,name,phone'])
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Return #', 'Order #', 'Customer', 'Phone', 'Stage', 'Status', 'Return Shipping Fee', 'Filed At'];
    }

    /**
     * @return list<mixed>
     */
    public function map($return): array
    {
        return [
            $return->id,
            $return->order?->order_number,
            $return->customer?->name,
            $return->customer?->phone,
            $return->stage->value,
            $return->status->value,
            $return->return_shipping_fee,
            $return->created_at?->toDateTimeString(),
        ];
    }
}
