<?php

namespace Database\Seeders;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReviewStatus;
use App\Models\Address;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Review;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Data for the customer-facing screens that a product catalogue alone cannot
 * fill: a sign-in-ready customer, realistic reviews, a saved address,
 * wishlist, and a small order history. It intentionally never adjusts stock;
 * these are historical presentation records, not a substitute for checkout.
 */
class StorefrontDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $customer = Customer::updateOrCreate(
            ['email' => 'demo@waqar.test'],
            ['name' => 'Layla Hassan', 'phone' => '+201100000001', 'password' => 'password', 'is_active' => true, 'is_guest' => false],
        );
        $reviewers = [
            $customer,
            $this->customer('sara@waqar.test', 'Sara Adel', '+201100000002'),
            $this->customer('nour@waqar.test', 'Nour Ali', '+201100000003'),
            $this->customer('mariam@waqar.test', 'Mariam Samy', '+201100000004'),
        ];

        $area = Area::query()->with('city.governorate')->first();
        if ($area === null || $area->city === null || $area->city->governorate === null) {
            return;
        }

        Address::updateOrCreate(
            ['customer_id' => $customer->id, 'label' => 'Home'],
            [
                'recipient_name' => $customer->name,
                'phone' => $customer->phone,
                'governorate_id' => $area->city->governorate->id,
                'city_id' => $area->city->id,
                'district_id' => $area->district_id,
                'area_id' => $area->id,
                'address_line' => '12 El Merghany Street, Heliopolis',
                'is_default' => true,
            ],
        );

        $products = Product::query()->with('variants')->get()->keyBy('sku');
        $deliveredItem = $this->seedOrder($customer, 2001, OrderStatus::Delivered, $area, $products, [
            ['PRD-005', 1], ['PRD-001', 2],
        ]);
        $this->seedOrder($customer, 2002, OrderStatus::OutForDelivery, $area, $products, [['PRD-004', 1]]);
        $this->seedOrder($customer, 2003, OrderStatus::New, $area, $products, [['PRD-002', 1]]);

        $reviews = [
            ['PRD-005', 5, 'Beautiful leather', 'The fit is relaxed and the leather feels much nicer than I expected.', $reviewers[0], $deliveredItem['PRD-005'] ?? null],
            ['PRD-001', 5, 'A true everyday coat', 'Clean, well-cut, and easy to wear over everything.', $reviewers[1], null],
            ['PRD-002', 4, 'Lovely colour', 'The stripes are vibrant and the fit is spot on.', $reviewers[2], null],
            ['PRD-004', 5, 'Perfect relaxed fit', 'Exactly the corduroy layer I was looking for.', $reviewers[3], null],
            ['PRD-003', 4, 'Great statement piece', 'The pockets are practical and the finish looks polished.', $reviewers[1], null],
            ['PRD-001', 5, 'Warm but not bulky', 'A very easy coat to throw on for cooler evenings.', $reviewers[2], null],
        ];

        foreach ($reviews as [$sku, $rating, $title, $comment, $reviewer, $orderItem]) {
            $product = $products->get($sku);
            if ($product === null) {
                continue;
            }

            Review::updateOrCreate(
                ['product_id' => $product->id, 'customer_id' => $reviewer->id],
                [
                    'order_item_id' => $orderItem?->id,
                    'rating' => $rating,
                    'title' => $title,
                    'comment' => $comment,
                    'status' => ReviewStatus::Approved,
                ],
            );
        }

        $wishlist = Wishlist::firstOrCreate(['customer_id' => $customer->id]);
        foreach (['PRD-003', 'PRD-004', 'PRD-002'] as $sku) {
            if ($product = $products->get($sku)) {
                WishlistItem::firstOrCreate(['wishlist_id' => $wishlist->id, 'product_id' => $product->id]);
            }
        }
    }

    private function customer(string $email, string $name, string $phone): Customer
    {
        return Customer::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'phone' => $phone, 'password' => 'password', 'is_active' => true, 'is_guest' => false],
        );
    }

    /**
     * @param  Collection<string, Product>  $products
     * @param  array<int, array{0: string, 1: int}>  $lines
     * @return array<string, OrderItem>
     */
    private function seedOrder(Customer $customer, int $number, OrderStatus $status, Area $area, $products, array $lines): array
    {
        $items = [];
        $subtotal = 0.0;

        foreach ($lines as [$sku, $quantity]) {
            $product = $products->get($sku);
            $variant = $product?->variants->first();
            if ($product === null || $variant === null) {
                continue;
            }

            $price = (float) ($variant->sale_price ?? $variant->price ?? $product->sale_price ?? $product->price);
            $items[] = compact('product', 'variant', 'quantity', 'price');
            $subtotal += $price * $quantity;
        }

        if ($items === []) {
            return [];
        }

        $shipping = 35.0;
        $order = Order::updateOrCreate(
            ['order_number' => $number],
            [
                'customer_id' => $customer->id,
                'order_source' => OrderSource::Website,
                'status' => $status,
                'customer_status' => $status->customerStatus(),
                'payment_status' => $status === OrderStatus::Delivered ? PaymentStatus::Collected : PaymentStatus::Pending,
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'shipping_amount' => $shipping,
                'total' => $subtotal + $shipping,
                'shipping_recipient_name' => $customer->name,
                'shipping_phone' => $customer->phone,
                'shipping_governorate_id' => $area->city->governorate->id,
                'shipping_city_id' => $area->city->id,
                'shipping_district_id' => $area->district_id,
                'shipping_area_id' => $area->id,
                'shipping_address_line' => '12 El Merghany Street, Heliopolis',
                'notes' => 'Seeded storefront demonstration order.',
            ],
        );

        $created = [];
        foreach ($items as $item) {
            $line = OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'product_variant_id' => $item['variant']->id],
                [
                    'product_name_snapshot' => $item['product']->getTranslation('name', 'en'),
                    'variant_sku_snapshot' => $item['variant']->sku,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'subtotal' => $item['price'] * $item['quantity'],
                ],
            );
            $created[$item['product']->sku] = $line;
        }

        $path = match ($status) {
            OrderStatus::Delivered => [OrderStatus::New, OrderStatus::Checking, OrderStatus::Confirmed, OrderStatus::Assigned, OrderStatus::OutForDelivery, OrderStatus::Delivered],
            OrderStatus::OutForDelivery => [OrderStatus::New, OrderStatus::Checking, OrderStatus::Confirmed, OrderStatus::Assigned, OrderStatus::OutForDelivery],
            default => [OrderStatus::New],
        };
        $previous = null;
        foreach ($path as $step) {
            OrderStatusHistory::firstOrCreate(
                ['order_id' => $order->id, 'to_status' => $step->value],
                ['from_status' => $previous?->value, 'notes' => 'Seeded demonstration status transition.'],
            );
            $previous = $step;
        }

        Payment::updateOrCreate(
            ['order_id' => $order->id],
            [
                'method' => 'cod',
                'status' => $status === OrderStatus::Delivered ? PaymentStatus::Collected : PaymentStatus::Pending,
                'amount' => $order->total,
                'collected_amount' => $status === OrderStatus::Delivered ? $order->total : null,
                'collected_at' => $status === OrderStatus::Delivered ? now()->subDays(5) : null,
                'collection_type' => $status === OrderStatus::Delivered ? 'full' : null,
                'collected_method' => $status === OrderStatus::Delivered ? 'cash' : null,
            ],
        );

        return $created;
    }
}
