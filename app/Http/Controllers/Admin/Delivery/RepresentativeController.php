<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\DeliveryRepresentativeArea;
use App\Models\Order;
use App\Models\Payment;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/delivery/representatives (Section 14) — plain CRUD, no
 * dedicated Action needed (no cross-cutting business rule beyond what
 * validation already covers).
 */
class RepresentativeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:delivery.representatives.view', only: ['index', 'show', 'areas']),
            new Middleware('permission:delivery.representatives.create', only: ['create', 'store']),
            // Coverage-area add/remove folds into .update rather than getting
            // its own permission — it isn't a separate screen, just part of
            // editing what a representative covers.
            new Middleware('permission:delivery.representatives.update', only: ['edit', 'update', 'storeArea', 'destroyArea']),
            new Middleware('permission:delivery.representatives.delete', only: ['destroy']),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Delivery/Representatives/Index', [
            'representatives' => DeliveryRepresentative::query()->withCount('areas')->latest('id')->paginate(20)->withQueryString(),
        ]);
    }

    /**
     * One delivery man's profile: who they are, where they deliver
     * (coverage areas), and every order currently or previously on
     * their back — with delivered orders split into settled vs.
     * still-owed, so Accounting can see at a glance what cash is
     * still out with this courier.
     *
     * Money math reuses Order::netOfShipping(), the single source of
     * the "courier keeps the shipping" rule (see Order), measured
     * against the latest payment exactly like
     * ConfirmDeliveryResultAction::collectBalance() does — never
     * against the gross total, which would mark every settled order
     * short by exactly the shipping.
     */
    public function show(Request $request, DeliveryRepresentative $representative): Response
    {
        $employee = $request->user('employee');

        $profile = [
            'representative' => $representative->loadCount('areas'),
            'areas' => $representative->areas()->latest('id')->get(),
            'geoTree' => GeoTree::tree(),
        ];

        // The courier's order history and cash position are Delivery Board
        // data: delivery.representatives.view alone shows the profile and
        // coverage, not the orders.
        if (! $employee->can('delivery.view')) {
            return Inertia::render('Delivery/Representatives/Show', [...$profile, 'stats' => null, 'orders' => null]);
        }

        $base = Order::query()
            ->visibleTo($employee)
            ->where('delivery_representative_id', $representative->id);

        $totalOrders = (clone $base)->count();
        $activeOrders = (clone $base)->whereIn('status', [OrderStatus::Assigned, OrderStatus::OutForDelivery])->count();
        $deliveredOrders = (clone $base)->where('status', OrderStatus::Delivered)->count();
        $partiallyReturnedOrders = (clone $base)->where('status', OrderStatus::PartiallyReturned)->count();
        $returnedOrders = (clone $base)->where('status', OrderStatus::Returned)->count();
        $outstandingOrders = (clone $base)->where('payment_status', PaymentStatus::PartiallyCollected)->count();

        // Still-owed cash sitting with this courier. The outstanding set
        // is small by construction (delivered-but-short only), so sum in
        // PHP over the loaded payments rather than approximating in SQL.
        $outstandingBalance = (clone $base)
            ->where('payment_status', PaymentStatus::PartiallyCollected)
            ->with(['payments' => fn ($query) => $query->latest('id')->limit(1)])
            ->get(['id', 'total', 'shipping_amount', 'payment_status'])
            ->sum(fn (Order $order) => $this->stillOwed($order));

        $collectedTotal = (float) Payment::query()
            ->where('status', PaymentStatus::Collected)
            ->whereIn('order_id', (clone $base)->select('id'))
            ->sum('collected_amount');

        $orders = (clone $base)
            ->with([
                'customer:id,name,phone',
                'payments' => fn ($query) => $query->latest('id')->limit(1),
                'shippingGovernorate:id,name',
                'shippingCity:id,name',
                'shippingDistrict:id,name',
                'shippingArea:id,name',
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (Order $order) {
                $payment = $order->payments->first();
                $gross = $payment !== null ? (float) $payment->amount : (float) $order->total;
                $collected = $payment !== null ? (float) $payment->collected_amount : 0.0;

                return [
                    ...$order->toArray(),
                    'net_due' => round($order->netOfShipping($gross), 2),
                    'collected_amount' => $collected,
                    'still_owed' => $this->stillOwed($order),
                    'is_delivered' => in_array($order->status, [OrderStatus::Delivered, OrderStatus::PartiallyReturned], true),
                ];
            });

        return Inertia::render('Delivery/Representatives/Show', [
            ...$profile,
            'stats' => [
                'total_orders' => $totalOrders,
                'active_orders' => $activeOrders,
                'delivered_orders' => $deliveredOrders,
                'partially_returned_orders' => $partiallyReturnedOrders,
                'returned_orders' => $returnedOrders,
                'outstanding_orders' => $outstandingOrders,
                'outstanding_balance' => round($outstandingBalance, 2),
                'collected_total' => round($collectedTotal, 2),
            ],
            'orders' => $orders,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Delivery/Representatives/Form', ['representative' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DeliveryRepresentative::create($data);

        return redirect()->route('admin.delivery.representatives.index')->with('success', __('Representative created.'));
    }

    public function edit(DeliveryRepresentative $representative): Response
    {
        return Inertia::render('Delivery/Representatives/Form', ['representative' => $representative]);
    }

    public function update(Request $request, DeliveryRepresentative $representative): RedirectResponse
    {
        $representative->update($this->validated($request));

        return redirect()->route('admin.delivery.representatives.index')->with('success', __('Representative updated.'));
    }

    public function destroy(DeliveryRepresentative $representative): RedirectResponse
    {
        $representative->delete();

        return redirect()->route('admin.delivery.representatives.index')->with('success', __('Representative deleted.'));
    }

    public function areas(DeliveryRepresentative $representative): Response
    {
        return Inertia::render('Delivery/Representatives/Areas', [
            'representative' => $representative,
            'areas' => $representative->areas()->latest('id')->get(),
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function storeArea(Request $request, DeliveryRepresentative $representative): RedirectResponse
    {
        $data = $request->validate([
            'geo_type' => ['required', Rule::in(['governorate', 'city', 'district', 'area'])],
            'geo_id' => ['required', 'integer'],
        ]);

        // Coverage rows are soft-deleted, so re-adding an area a rep used to
        // cover would otherwise stack a second live row behind an invisible
        // trashed one. Revive instead — there is no unique index forcing
        // this, but two rows meaning the same coverage is still wrong.
        // Queried off the model rather than the relation: onlyTrashed() lives
        // on the SoftDeletes builder, which a HasMany only forwards to at
        // runtime — Larastan cannot see through it.
        $trashed = DeliveryRepresentativeArea::onlyTrashed()
            ->where('delivery_representative_id', $representative->id)
            ->where('geo_type', $data['geo_type'])
            ->where('geo_id', $data['geo_id'])
            ->first();

        if ($trashed !== null) {
            $trashed->restore();
        } else {
            $representative->areas()->create($data);
        }

        return back()->with('success', __('Coverage area added.'));
    }

    public function destroyArea(DeliveryRepresentative $representative, DeliveryRepresentativeArea $area): RedirectResponse
    {
        abort_unless($area->delivery_representative_id === $representative->id, 404);

        $area->delete();

        return back()->with('success', __('Coverage area removed.'));
    }

    /**
     * What this order still owes the treasury: net of shipping (what
     * reaches the drawer once the courier keeps their fee) less what
     * was already collected. Non-zero only while the payment sits at
     * partially_collected — settled, pending and refused orders owe
     * nothing here by definition.
     */
    private function stillOwed(Order $order): float
    {
        if ($order->payment_status !== PaymentStatus::PartiallyCollected) {
            return 0.0;
        }

        $payment = $order->relationLoaded('payments')
            ? $order->payments->sortByDesc('id')->first()
            : $order->payments()->latest('id')->first();

        if ($payment === null) {
            return 0.0;
        }

        return max(0.0, round($order->netOfShipping((float) $payment->amount) - (float) $payment->collected_amount, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
