<?php

namespace App\Http\Controllers\Store\Concerns;

use App\Models\Customer;
use App\Models\Wishlist;

trait ResolvesWishlist
{
    /**
     * One wishlist per customer (Section 24), created lazily rather than
     * at registration so a customer who never uses it has no row.
     */
    protected function wishlistFor(Customer $customer): Wishlist
    {
        return Wishlist::firstOrCreate(['customer_id' => $customer->id]);
    }
}
