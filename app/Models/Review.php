<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property ReviewStatus $status
 * @property-read Customer $customer
 * @property-read Product $product
 */
class Review extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'product_id',
        'customer_id',
        'order_item_id',
        'rating',
        'title',
        'comment',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('review_images');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Non-null means the reviewer actually bought this line — what marks
     * the review verified-purchase (Section 24).
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
