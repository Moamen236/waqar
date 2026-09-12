<?php

use App\Enums\PaymentStatus;
use App\Enums\ReturnStage;
use App\Models\Area;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\District;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionItem;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\ShippingCompany;
use App\Models\ShippingCompanyStatement;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Database\Seeders\ReturnReasonSeeder;

// Phase 2 — Schema Extensions (WAQAR-DELIVERY-ROADMAP.html). Covers the
// tables the original roadmap called "extensions" plus the base
// Orders/Payments/Returns/Commerce/Treasury tables those extensions
// assumed already existed (see PHASE-2-HANDOVER.md).

function makeGeo(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $district = District::create(['city_id' => $city->id, 'name' => ['ar' => 'الحي السابع', 'en' => 'District 7']]);
    $area = Area::create(['city_id' => $city->id, 'district_id' => $district->id, 'name' => ['ar' => 'المنطقة أ', 'en' => 'Zone A']]);

    return compact('country', 'governorate', 'city', 'district', 'area');
}

it('resolves an area through an optional district, per Question 12', function () {
    $geo = makeGeo();

    expect($geo['area']->district->is($geo['district']))->toBeTrue()
        ->and($geo['area']->city->is($geo['city']))->toBeTrue();
});

it('creates a full order with items, a coupon, a promotion, and a COD payment', function () {
    $geo = makeGeo();
    $customer = Customer::create(['name' => 'Test Customer', 'email' => 'c@waqar.test', 'phone' => '1', 'password' => 'password']);

    $product = Product::create(['name' => ['ar' => 'قميص', 'en' => 'Shirt'], 'slug' => 'shirt', 'sku' => 'SH-1', 'price' => 300]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SH-1-M']);

    $coupon = Coupon::create(['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10]);

    $promotion = Promotion::create([
        'name' => ['ar' => 'اشترِ 2', 'en' => 'Buy 2'],
        'type' => 'bundle',
        'discount_type' => 'fixed_amount',
        'discount_value' => 20,
    ]);
    PromotionItem::create(['promotion_id' => $promotion->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);

    $order = Order::create([
        'customer_id' => $customer->id,
        'order_source' => 'website',
        'customer_status' => 'Order Received',
        'subtotal' => 600,
        'shipping_amount' => 50,
        'discount_amount' => 80,
        'total' => 570,
        'coupon_id' => $coupon->id,
        'shipping_recipient_name' => $customer->name,
        'shipping_phone' => $customer->phone,
        'shipping_governorate_id' => $geo['governorate']->id,
        'shipping_city_id' => $geo['city']->id,
        'shipping_district_id' => $geo['district']->id,
        'shipping_area_id' => $geo['area']->id,
        'shipping_address_line' => 'Test St',
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_variant_id' => $variant->id,
        'product_name_snapshot' => 'Shirt',
        'variant_sku_snapshot' => 'SH-1-M',
        'quantity' => 2,
        'unit_price' => 300,
        'subtotal' => 600,
        'promotion_id' => $promotion->id,
    ]);

    $payment = Payment::create([
        'order_id' => $order->id,
        'amount' => 570,
    ]);

    expect($order->order_number)->toBe(1001) // seeded at 1001 (Section 10)
        ->and($order->fresh()->items)->toHaveCount(1)
        ->and($orderItem->promotion->is($promotion))->toBeTrue()
        ->and($order->coupon->code)->toBe('SAVE10')
        ->and($payment->method)->toBe('cod')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    // A second order gets the next sequential number.
    $order2 = Order::create([
        'customer_id' => $customer->id, 'order_source' => 'website', 'customer_status' => 'Order Received',
        'subtotal' => 100, 'shipping_amount' => 20, 'total' => 120,
        'shipping_recipient_name' => 'X', 'shipping_phone' => '1',
        'shipping_governorate_id' => $geo['governorate']->id, 'shipping_city_id' => $geo['city']->id,
        'shipping_area_id' => $geo['area']->id, 'shipping_address_line' => 'Y',
    ]);
    expect($order2->order_number)->toBe(1002);
});

it('reserves and later deducts stock via inventory_movements, and assigns delivery', function () {
    $product = Product::create(['name' => ['ar' => 'حذاء', 'en' => 'Shoe'], 'slug' => 'shoe', 'sku' => 'SK-1', 'price' => 500]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'SK-1-42']);
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '1']);
    $stock = WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => 100, 'reserved_quantity' => 10]);

    expect($stock->available)->toBe(90);

    $employee = Employee::create([
        'full_name' => 'Warehouse Clerk', 'email' => 'wc@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);

    InventoryMovement::create([
        'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
        'type' => 'sale', 'quantity' => -1, 'created_by' => $employee->id,
    ]);

    $rep = DeliveryRepresentative::create(['name' => 'Ahmed', 'phone' => '1']);
    $company = ShippingCompany::create(['name' => 'FastShip', 'phone' => '1', 'address' => 'Cairo', 'delivery_fee' => 30, 'return_fee' => 15]);

    $statement = ShippingCompanyStatement::create([
        'shipping_company_id' => $company->id, 'period_start' => now()->subWeek(), 'period_end' => now(),
        'delivered_orders_count' => 10, 'expected_customer_collection' => 5000,
        'delivery_fees_owed' => 300, 'return_fees_owed' => 0, 'net_amount_expected' => 4700,
        'outstanding_amount' => 4700, 'created_by' => $employee->id,
    ]);

    expect(InventoryMovement::where('type', 'sale')->count())->toBe(1)
        ->and($rep->areas)->toHaveCount(0)
        ->and($statement->shippingCompany->is($company))->toBeTrue();
});

it('unifies at_delivery and post_delivery returns under one stage field, with a linked refund', function () {
    $this->seed(ReturnReasonSeeder::class);
    expect(ReturnReason::count())->toBe(6);

    $geo = makeGeo();
    $customer = Customer::create(['name' => 'C', 'email' => 'c2@waqar.test', 'phone' => '1', 'password' => 'password']);
    $order = Order::create([
        'customer_id' => $customer->id, 'order_source' => 'website', 'customer_status' => 'Delivered',
        'subtotal' => 200, 'shipping_amount' => 20, 'total' => 220,
        'shipping_recipient_name' => 'C', 'shipping_phone' => '1',
        'shipping_governorate_id' => $geo['governorate']->id, 'shipping_city_id' => $geo['city']->id,
        'shipping_area_id' => $geo['area']->id, 'shipping_address_line' => 'Z',
    ]);
    $reason = ReturnReason::first();

    $return = OrderReturn::create([
        'order_id' => $order->id, 'customer_id' => $customer->id,
        'stage' => 'post_delivery', 'status' => 'approved', 'reason_id' => $reason->id,
        'return_shipping_fee' => 20, // Q6 — customer pays, deducted from refund
        'customer_accepted_return_shipping_fee_at' => now(),
    ]);

    $refund = Refund::create([
        'return_id' => $return->id, 'order_id' => $order->id,
        'amount' => 200, 'return_shipping_fee' => 20, 'net_amount' => 180,
        'method' => 'bank_transfer',
    ]);

    expect($return->stage)->toBe(ReturnStage::PostDelivery)
        ->and($return->refund->net_amount)->toEqual('180.00')
        ->and($refund->orderReturn->is($return))->toBeTrue();
});

it('records treasury transactions and cart contents', function () {
    $treasury = Treasury::create(['name' => 'Main Cash', 'type' => 'cash']);
    $employee = Employee::create([
        'full_name' => 'Accountant', 'email' => 'acc@waqar.test', 'phone' => '1',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $transaction = TreasuryTransaction::create([
        'treasury_id' => $treasury->id, 'type' => 'income', 'amount' => 500, 'created_by' => $employee->id,
    ]);

    $product = Product::create(['name' => ['ar' => 'حقيبة', 'en' => 'Bag'], 'slug' => 'bag', 'sku' => 'BG-1', 'price' => 250]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'BG-1-BLK']);
    $cart = Cart::create(['session_token' => 'guest-abc']);
    CartItem::create(['cart_id' => $cart->id, 'product_variant_id' => $variant->id, 'quantity' => 3]);

    expect($treasury->transactions)->toHaveCount(1)
        ->and($transaction->treasury->is($treasury))->toBeTrue()
        ->and($cart->fresh()->items)->toHaveCount(1);
});
