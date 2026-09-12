<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;

class Product extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia, RecordsActivity, SerializesTranslations, SoftDeletes;

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
            'name',
            'slug',
            'sku',
            'price',
            'sale_price',
            'cost_price',
            'status',
            'product_type',
            'inventory_tracking_enabled',
            'is_featured',
        ];
    }

    public array $translatable = [
        'name',
        'description',
        'short_description',
        'meta_title',
        'meta_description',
    ];

    // Mirrors the DB defaults — inventory_tracking_enabled in particular
    // is what CreateOrderAction's stock-check bypass reads directly
    // (Section 05), so it must read correctly on a freshly created
    // instance, not only after a round-trip.
    protected $attributes = [
        'product_type' => 'real',
        'inventory_tracking_enabled' => true,
    ];

    protected $fillable = [
        'name',
        'slug',
        'sku',
        'description',
        'short_description',
        'price',
        'sale_price',
        'cost_price',
        'status',
        'is_featured',
        'is_new',
        'is_on_sale',
        'sort_order',
        'meta_title',
        'meta_description',
        'product_type',
        'inventory_tracking_enabled',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2', // internal-only — never expose in a storefront resource/response
            'status' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_on_sale' => 'boolean',
            'inventory_tracking_enabled' => 'boolean',
            'product_type' => ProductType::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('product_images');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Product-level attributes (e.g. Material) shown across all variants —
     * for filtering, not SKU differentiation. See ProductVariant::
     * attributeValues() for the SKU-differentiating pivot.
     */
    /**
     * @return BelongsToMany<AttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_attribute_values');
    }

    /**
     * Many-to-many, replaces the single category_id the source documents
     * implied (Section 05, §20 #21).
     */
    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories');
    }

    /**
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_product');
    }

    /**
     * Every review, in any moderation state — the storefront always
     * filters to Approved (ReviewStatus), the account area shows a
     * customer their own pending ones too.
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
