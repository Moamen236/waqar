<?php

namespace App\Exports;

use App\Models\WarehouseInventory;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The same rows /admin/inventory shows — same warehouse/search filters —
 * as an .xlsx download instead of a paginated table.
 */
class InventoryExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly ?int $warehouseId = null,
        private readonly string $search = '',
    ) {}

    public function query(): Builder
    {
        return WarehouseInventory::query()
            ->with(['warehouse:id,name', 'productVariant:id,product_id,sku', 'productVariant.product:id,name'])
            ->when($this->warehouseId, fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->search !== '', fn ($query) => $query->whereHas(
                'productVariant',
                fn ($variant) => $variant->where('sku', 'like', "%{$this->search}%")
            ))
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['SKU', 'Product', 'Warehouse', 'On Hand', 'Reserved', 'Available'];
    }

    /**
     * @return list<mixed>
     */
    public function map($row): array
    {
        return [
            $row->productVariant?->sku,
            $row->productVariant?->product?->name,
            $row->warehouse?->name,
            $row->quantity,
            $row->reserved_quantity,
            $row->available,
        ];
    }
}
