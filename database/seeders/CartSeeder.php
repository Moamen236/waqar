<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * In-progress carts (Section 08) — one for a signed-in customer, one for
 * an anonymous session — so the cart/mini-cart screens have something to
 * render before a single checkout has happened in this environment.
 */
class CartSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $customer = Customer::where('email', 'sara@waqar.test')->first();
        if ($customer !== null) {
            $cart = Cart::firstOrCreate(['customer_id' => $customer->id]);
            $this->addItem($cart, 'PRD-003', 1);
            $this->addItem($cart, 'PRD-002', 2);
        }

        $guestCart = Cart::firstOrCreate(['session_token' => 'demo-guest-session-token']);
        $this->addItem($guestCart, 'PRD-005', 1);
    }

    private function addItem(Cart $cart, string $sku, int $quantity): void
    {
        $variant = Product::where('sku', $sku)->first()?->variants()->first();
        if ($variant === null) {
            return;
        }

        $cart->items()->firstOrCreate(
            ['product_variant_id' => $variant->id],
            ['quantity' => $quantity],
        );
    }
}
