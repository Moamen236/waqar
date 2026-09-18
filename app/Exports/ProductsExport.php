<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * The same rows /admin/products shows — same `q` search — as an .xlsx
 * download instead of a paginated table. cost_price is internal-only
 * (never shown in a storefront resource/response) so it is included here
 * only for an employee who already holds products.update, mirroring the
 * same gate ProductController::show() applies to that column.
 */
class ProductsExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private readonly string $search = '',
        private readonly bool $includeCostPrice = false,
    ) {}

    public function query(): Builder
    {
        return Product::query()
            ->when($this->search !== '', function ($query) {
                $term = "%{$this->search}%";
                $query->where('sku', 'like', $term)->orWhere('name->en', 'like', $term);
            })
            ->with('categories:id,name')
            ->withCount('variants')
            ->orderByDesc('id');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $headings = ['SKU', 'Name', 'Type', 'Price', 'Sale Price'];
        if ($this->includeCostPrice) {
            $headings[] = 'Cost Price';
        }

        return [...$headings, 'Status', 'Variants', 'Categories'];
    }

    /**
     * @return list<mixed>
     */
    public function map($product): array
    {
        $row = [
            $product->sku,
            $product->name,
            $product->product_type->value,
            $product->price,
            $product->sale_price,
        ];

        if ($this->includeCostPrice) {
            $row[] = $product->cost_price;
        }

        return [
            ...$row,
            $product->status ? 'Active' : 'Inactive',
            $product->variants_count,
            $product->categories->pluck('name')->implode(', '),
        ];
    }
}
