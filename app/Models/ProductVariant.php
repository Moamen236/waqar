<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read Product $product
 */
class ProductVariant extends Model
{
    use RecordsActivity;

    protected function activityLogName(): string
    {
        return 'catalog';
    }

    /**
     * @return list<string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'product_id',
            'sku',
            'barcode',
            'price',
            'sale_price',
            'cost_price',
            'status',
        ];
    }

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'price',
        'sale_price',
        'cost_price',
        'size_guide_weight_min',
        'size_guide_weight_max',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'size_guide_weight_min' => 'decimal:2',
            'size_guide_weight_max' => 'decimal:2',
            'status' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The variant's own price overrides the product's, when set — sale
     * price wins over regular price at whichever level actually has one
     * (Section 05).
     */
    public function effectivePrice(): float
    {
        if ($this->sale_price !== null) {
            return (float) $this->sale_price;
        }
        if ($this->price !== null) {
            return (float) $this->price;
        }

        return (float) ($this->product->sale_price ?? $this->product->price);
    }

    /**
     * Variant-defining attributes (Color, Size) — what actually
     * differentiates this SKU from the product's other variants.
     */
    /**
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values');
    }

    public function activitySubjectLabel(): string
    {
        return $this->sku ?? 'Variant #'.$this->getKey();
    }
}
