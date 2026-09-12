<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\SerializesTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class Category extends Model
{
    use HasTranslations, RecordsActivity, SerializesTranslations, SoftDeletes;

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
            'parent_id',
            'name',
            'slug',
            'status',
            'sort_order',
        ];
    }

    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    /**
     * Self-referencing nesting (spec Section 05) — unlimited depth.
     * Multi-category products go through product_categories (Phase 2),
     * not a single category_id on products.
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_categories');
    }
}
