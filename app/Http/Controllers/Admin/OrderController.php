<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exports\OrdersExport;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\Warehouse;
use App\Services\Checkout\CouponService;
use App\Services\Shipping\ShippingRateResolver;
use App\Support\GeoTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/orders/create (Section 08 Flow 2, Section 20 #13) — Customer
 * Service placing an order on a customer's behalf. Reuses
 * CreateOrderAction directly, the same Action storefront checkout will
 * call in Phase 5 — same validation, same server-side pricing, same COD
 * payment record, same stock reservation, regardless of who's placing it.
 */
class OrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:orders.create', only: ['create', 'store', 'quote']),
            new Middleware('permission:orders.view', only: ['index', 'show', 'invoice']),
            new Middleware('permission:orders.export', only: ['export']),
            new Middleware('permission:orders.delete', only: ['destroy']),
        ];
    }

    /**
     * /admin/orders — every order, whatever stage it is at.
     *
     * Ported from Admin Template/orders-list.html: its four summary tiles
     * over a single `table-hover table-centered` list card. The template's
     * tiles are a fixed set of demo counters; these count real orders by
     * the statuses this system actually has (Section 10), scoped through
     * `Order::visibleTo()` exactly like every other order listing — a
     * Customer Service Team Leader's totals match the rows they can see,
     * rather than the whole book.
     *
     * Read-only by construction. There is no status-changing route here:
     * confirming, assigning and closing an order stay on the department
     * screens that own those transitions, and the detail view links out
     * to them rather than duplicating the buttons.
     */
    public function index(Request $request): Response
    {
        $employee = $request->user('employee');
        $filters = self::orderFilters($request);

        $orders = Order::query()
            ->visibleTo($employee)
            ->filtered($filters)
            ->with(['customer:id,name,email,phone', 'deliveryRepresentative:id,name', 'shippingCompany:id,name'])
            ->withCount('items')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Counted off the same scope as the rows, so the tiles can never
        // disagree with the list underneath them.
        $countFor = fn (array $statuses) => Order::query()
            ->visibleTo($employee)
            ->whereIn('status', $statuses)
            ->count();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => array_map(fn (OrderStatus $case) => $case->value, OrderStatus::cases()),
            'geoTree' => GeoTree::tree(),
            'representatives' => DeliveryRepresentative::query()->orderBy('name')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->orderBy('name')->get(['id', 'name']),
            'summary' => [
                'awaiting_checking' => $countFor([OrderStatus::New->value, OrderStatus::Checking->value]),
                'in_delivery' => $countFor([OrderStatus::Assigned->value, OrderStatus::OutForDelivery->value]),
                'delivered' => $countFor([OrderStatus::Delivered->value]),
                'cancelled_or_returned' => $countFor([
                    OrderStatus::Cancelled->value,
                    OrderStatus::Returned->value,
                    OrderStatus::PartiallyReturned->value,
                ]),
            ],
        ]);
    }

    /**
     * The same rows index() renders — same visibleTo() scope, same
     * filters — as an .xlsx download.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $export = new OrdersExport(
            $request->user('employee'),
            self::orderFilters($request),
        );

        return $export->download('orders-'.now()->format('Y-m-d_His').'.xlsx');
    }

    /**
     * Validate the order-book filter query string once, for both the
     * listing and the export — the download can never drift from the
     * table it claims to match.
     *
     * @return array<string, mixed>
     */
    private static function orderFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string', 'max:255'],
            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'representative_id' => ['nullable', 'integer', 'exists:delivery_representatives,id'],
            'shipping_company_id' => ['nullable', 'integer', 'exists:shipping_companies,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'qty_min' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'qty_max' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        return [
            'status' => (string) ($validated['status'] ?? ''),
            'q' => (string) ($validated['q'] ?? ''),
            'customer' => (string) ($validated['customer'] ?? ''),
            'governorate_id' => $validated['governorate_id'] ?? null,
            'city_id' => $validated['city_id'] ?? null,
            'district_id' => $validated['district_id'] ?? null,
            'area_id' => $validated['area_id'] ?? null,
            'representative_id' => $validated['representative_id'] ?? null,
            'shipping_company_id' => $validated['shipping_company_id'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'qty_min' => $validated['qty_min'] ?? null,
            'qty_max' => $validated['qty_max'] ?? null,
        ];
    }

    /**
     * /admin/orders/{order} — the whole order on one page, ported from
     * Admin Template/order-detail.html (Product table, Order Timeline,
     * Order Summary / Payment Information / Customer Details sidebar).
     *
     * Also read-only. `visibleTo()` is applied as a *guard* here, not just
     * a filter: without it a Team Leader could read any order by guessing
     * its id, which is exactly the boundary the scope exists to hold.
     */
    public function show(Request $request, Order $order): Response
    {
        abort_unless(
            Order::query()->visibleTo($request->user('employee'))->whereKey($order->getKey())->exists(),
            403,
        );

        $order->load([
            'customer:id,name,email,phone',
            'items.productVariant.product:id,name',
            'statusHistory.changedBy:id,full_name',
            // Each collection made against the payment, so an order the
            // courier came back short on shows how it got paid off.
            'payments.transactions.treasury:id,name',
            'payments.transactions.createdBy:id,full_name',
            'deliveryRepresentative:id,name,phone',
            'shippingCompany:id,name',
            'createdByEmployee:id,full_name',
            'shippingGovernorate:id,name',
            'shippingCity:id,name',
            'shippingArea:id,name',
        ]);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            // Where this order can be acted on, if anywhere. The detail
            // view links to the owning department rather than growing its
            // own copy of those buttons.
            'workflow' => [
                'checking' => in_array($order->status, [
                    OrderStatus::New, OrderStatus::Checking, OrderStatus::Postponed, OrderStatus::Backorder,
                ], true),
                'delivery' => $order->status === OrderStatus::Confirmed,
                'accounting' => in_array($order->status, [
                    OrderStatus::Assigned, OrderStatus::OutForDelivery,
                ], true),
            ],
            'paymentStatuses' => array_map(fn (PaymentStatus $case) => $case->value, PaymentStatus::cases()),
        ]);
    }

    /**
     * /admin/orders/{order}/invoice — a print-friendly invoice carrying
     * every order detail (customer, shipping address, items, totals,
     * payments, delivery) under the WAQAR logo at
     * public/admin-theme/assets/images/logo-dark.png. The page falls back
     * to a WAQAR wordmark if the file ever goes missing, so it never
     * renders a broken image.
     *
     * Same visibleTo() guard as show(): the invoice must not leak an order
     * its reader is scoped away from.
     */
    public function invoice(Request $request, Order $order): Response
    {
        abort_unless(
            Order::query()->visibleTo($request->user('employee'))->whereKey($order->getKey())->exists(),
            403,
        );

        $order->load([
            'customer:id,name,email,phone',
            'items.productVariant.product:id,name',
            'payments',
            'deliveryRepresentative:id,name,phone',
            'shippingCompany:id,name',
            'createdByEmployee:id,full_name',
            'coupon:id,code',
            'shippingGovernorate:id,name',
            'shippingCity:id,name',
            'shippingDistrict:id,name',
            'shippingArea:id,name',
        ]);

        return Inertia::render('Orders/Invoice', [
            'order' => $order,
            'logo' => '/admin-theme/assets/images/logo-dark.png',
        ]);
    }

    /**
     * Soft-delete an order — remove it from the book without destroying
     * the record.
     *
     * **Only a Cancelled order can be deleted, and that is a correctness
     * rule, not a policy preference.** A live order holds a stock
     * reservation: `CreateOrderAction` reserves on placement, and
     * `CancelOrderAction` is what releases it back. `$order->delete()`
     * releases nothing — it only stamps `deleted_at` — so deleting a New
     * or Confirmed order would strand its reservation in
     * `warehouse_inventory.reserved_quantity` forever, permanently
     * shrinking available stock for an order nobody can see any more.
     *
     * Cancelling first is therefore a prerequisite rather than an
     * alternative: cancel (stock released, history written), then delete
     * (row hidden). A Delivered order is refused outright for the same
     * class of reason in the other direction — its stock deduction,
     * payment and treasury transaction are real, and hiding the order
     * they belong to would misstate the books.
     */
    public function destroy(Request $request, Order $order): RedirectResponse
    {
        abort_unless(
            Order::query()->visibleTo($request->user('employee'))->whereKey($order->getKey())->exists(),
            403,
        );

        if ($order->status !== OrderStatus::Cancelled) {
            return back()->with('error', __('Only a cancelled order can be deleted. Cancel it first so its stock reservation is released.'));
        }

        $order->delete();

        return redirect()->route('admin.orders.index')->with('success', __('Order removed from the book.'));
    }

    public function create(): Response
    {
        return Inertia::render('Orders/Create', [
            // Addresses ride along so picking a customer fills the shipping
            // card from what's already on file instead of retyping it.
            'customers' => Customer::query()
                ->orderBy('name')
                ->with(['addresses' => fn ($query) => $query->orderByDesc('is_default')->latest('id')])
                ->get(['id', 'name', 'email', 'phone'])
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'addresses' => $customer->addresses->map(fn (Address $address) => [
                        'id' => $address->id,
                        'label' => $address->label,
                        'governorate_id' => $address->governorate_id,
                        'city_id' => $address->city_id,
                        'district_id' => $address->district_id,
                        'area_id' => $address->area_id,
                        'address_line' => $address->address_line,
                        'is_default' => (bool) $address->is_default,
                    ])->values()->all(),
                ]),
            'variants' => ProductVariant::query()
                ->where('status', true)
                // whereHas() respects the product's soft-delete scope, so
                // a deleted product's variants drop out of the picker.
                // Without it `$variant->product` is null for them and this
                // screen 500s outright — a deleted product cannot be sold,
                // and should not be offerable.
                ->whereHas('product')
                ->with('product:id,name,sku')
                ->get()
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'label' => $variant->product->getTranslation('name', app()->getLocale()).' — '.$variant->sku,
                    'price' => $variant->effectivePrice(),
                ]),
            // No warehouse picker on this screen — orders always reserve
            // against the main warehouse; it is shown read-only for context.
            'warehouse' => Warehouse::main()?->only(['id', 'name']),
            'geoTree' => GeoTree::tree(),
        ]);
    }

    /**
     * Live totals for the half-filled create form — the same
     * ShippingRateResolver and CouponService CreateOrderAction prices the
     * real order with, so the figures on screen are the figures that will
     * be stored. Everything is optional: the screen asks for a quote as
     * soon as it has enough, and shows what it can until then.
     */
    public function quote(Request $request, ShippingRateResolver $rates, CouponService $coupons): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'items' => ['nullable', 'array'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'governorate_id' => ['nullable', 'exists:governorates,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ]);

        $variants = ProductVariant::query()
            ->whereIn('id', collect($data['items'] ?? [])->pluck('product_variant_id')->filter())
            ->get()
            ->keyBy('id');

        $subtotal = collect($data['items'] ?? [])->reduce(function (float $sum, array $item) use ($variants) {
            $variant = $variants->get($item['product_variant_id'] ?? null);

            return $variant === null
                ? $sum
                : $sum + round($variant->effectivePrice() * (int) ($item['quantity'] ?? 0), 2);
        }, 0.0);

        $rate = isset($data['governorate_id'], $data['city_id'], $data['area_id'])
            ? $rates->resolve(
                (int) $data['governorate_id'],
                (int) $data['city_id'],
                isset($data['district_id']) ? (int) $data['district_id'] : null,
                (int) $data['area_id'],
            )
            : null;

        $shipping = null;
        if ($rate !== null) {
            $shipping = (float) $rate->price;
            if ($rate->free_shipping_threshold !== null && $subtotal >= (float) $rate->free_shipping_threshold) {
                $shipping = 0.0;
            }
        }

        $discount = 0.0;
        $couponError = null;
        if (! empty($data['coupon_code'])) {
            try {
                // A customer being created inline has used nothing yet, so
                // an unsaved record answers the per-user limit correctly.
                $coupon = $coupons->resolve(
                    $data['coupon_code'],
                    isset($data['customer_id']) ? Customer::findOrFail($data['customer_id']) : new Customer,
                    $subtotal,
                );
                $discount = $coupons->discountFor($coupon, $subtotal);
                if ($coupon->type === 'free_shipping' && $shipping !== null) {
                    $shipping = 0.0;
                }
            } catch (InvalidArgumentException $e) {
                $couponError = $e->getMessage();
            }
        }

        return response()->json([
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'shipping' => $shipping,
            'total' => round($subtotal - $discount + ($shipping ?? 0.0), 2),
            'coupon_error' => $couponError,
        ]);
    }

    public function store(Request $request, CreateOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            // Either an existing customer, or one being filed inline on
            // this same screen — never both, never neither.
            'customer_id' => ['required_without:new_customer', 'nullable', 'exists:customers,id'],
            'new_customer' => ['required_without:customer_id', 'nullable', 'array'],
            'new_customer.name' => ['required_with:new_customer', 'string', 'max:255'],
            'new_customer.phone' => ['required_with:new_customer', 'string', 'max:30'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'governorate_id' => ['required', 'exists:governorates,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'area_id' => ['required', 'exists:areas,id'],
            'address_line' => ['required', 'string', 'max:500'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ]);

        // The form no longer offers a choice, so an absent warehouse_id
        // falls back to the main warehouse rather than failing validation.
        $warehouse = isset($data['warehouse_id'])
            ? Warehouse::findOrFail($data['warehouse_id'])
            : Warehouse::main();

        abort_if($warehouse === null, 422, __('No active warehouse is configured.'));

        $customer = isset($data['customer_id'])
            ? Customer::findOrFail($data['customer_id'])
            : $this->createInlineCustomer($data);

        $order = $action->execute(
            customer: $customer,
            items: $data['items'],
            warehouse: $warehouse,
            governorateId: (int) $data['governorate_id'],
            cityId: (int) $data['city_id'],
            districtId: isset($data['district_id']) ? (int) $data['district_id'] : null,
            areaId: (int) $data['area_id'],
            addressLine: $data['address_line'],
            recipientName: $data['recipient_name'],
            phone: $data['phone'],
            orderSource: OrderSource::CustomerService,
            createdByEmployee: $request->user('employee'),
            couponCode: $data['coupon_code'] ?? null,
        );

        return redirect()
            ->route('admin.checking.index')
            ->with('success', __('Order #:number created for :customer.', ['number' => $order->order_number, 'customer' => $order->customer->name]));
    }

    /**
     * A customer filed from the order screen itself — same arrangement
     * CustomerController::store() uses for a phone order or a walk-in: no
     * email, a generated password nobody can log in with, is_guest true.
     * The shipping address typed for this order is kept as their default,
     * so the next order can just pick it off the address list.
     *
     * @param  array<string, mixed>  $data
     */
    private function createInlineCustomer(array $data): Customer
    {
        $customer = Customer::create([
            'name' => $data['new_customer']['name'],
            'phone' => $data['new_customer']['phone'],
            'password' => Str::password(32),
            'is_guest' => true,
        ]);

        $customer->addresses()->create([
            'governorate_id' => (int) $data['governorate_id'],
            'city_id' => (int) $data['city_id'],
            'district_id' => isset($data['district_id']) ? (int) $data['district_id'] : null,
            'area_id' => (int) $data['area_id'],
            'address_line' => $data['address_line'],
            'recipient_name' => $data['recipient_name'],
            'phone' => $data['phone'],
            'is_default' => true,
        ]);

        return $customer;
    }
}
