<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/orders/create (Section 08 Flow 2, Section 20 #13) — Customer
 * Service placing an order on a customer's behalf. Reuses
 * CreateOrderAction directly, the same Action storefront checkout will
 * call in Phase 5 — same validation, same server-side pricing, same COD
 * payment record, same stock reservation, regardless of who's placing it.
 */
class OrderController extends Controller
{
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
        $status = $request->string('status')->toString();
        $search = trim((string) $request->string('q'));

        $orders = Order::query()
            ->visibleTo($employee)
            ->with(['customer:id,name,email,phone', 'deliveryRepresentative:id,name', 'shippingCompany:id,name'])
            ->withCount('items')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(
                fn ($inner) => $inner
                    ->where('order_number', 'like', "%{$search}%")
                    ->orWhere('shipping_phone', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
            ))
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
            'filters' => ['status' => $status, 'q' => $search],
            'statuses' => array_map(fn (OrderStatus $case) => $case->value, OrderStatus::cases()),
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
            'payments',
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
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'email', 'phone']),
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
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function store(Request $request, CreateOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
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

        $order = $action->execute(
            customer: Customer::findOrFail($data['customer_id']),
            items: $data['items'],
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
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
            ->with('success', "Order #{$order->order_number} created for {$order->customer->name}.");
    }
}
