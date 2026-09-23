<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Orders\CollectFromCourierAction;
use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmHandoverAction;
use App\Enums\CollectedMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Order;
use App\Models\ShippingCompany;
use App\Models\Treasury;
use App\Support\DateRangeFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * /admin/accounting (Section 14) — orders awaiting a delivery outcome,
 * and recording that outcome. This is the only place physical stock is
 * deducted and the only place a COD collection becomes a treasury
 * transaction (Phase 3's ConfirmDeliveryResultAction, CLAUDE.md's
 * central rule).
 */
class AccountingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:orders.view', only: ['index', 'show']),
            // Handover rides on the same grant: whoever may settle the
            // cash may sign the goods out. Splitting it would only matter
            // if the two were done by different people, and they are not.
            new Middleware('permission:orders.confirm_delivery', only: ['handover', 'delivered', 'returned', 'partiallyReturned', 'collect', 'settleBulk', 'collectFromCourier']),
        ];
    }

    public function index(Request $request): Response
    {
        // Settlement happens courier by courier: one of them turns up with
        // a bag of cash for everything they carried, so every queue
        // filters to that person and totals what they owe.
        $courier = $this->courierFilter($request);
        $employee = $request->user('employee');

        // Defaults to today, by order date, like the history screens —
        // asked for here even though this is a work queue. "All dates" on
        // the filter is one click away for the orders left from earlier
        // days; the per-courier totals ignore it (see courierBalances()).
        $dates = DateRangeFilter::fromRequest($request);

        // Each row carries `still_owed`, computed once here by the same
        // Order::stillOwed() CollectFromCourierAction splits against, so
        // the figures on screen are the figures that get banked.
        $withOwed = fn (Order $order) => $order->setAttribute('still_owed', $order->stillOwed());
        /** @var \Closure(): Builder<Order> $base */
        $base = fn () => DateRangeFilter::apply(
            $this->forCourier(Order::query()->visibleTo($employee), $courier),
            $dates,
            'orders.created_at',
        );

        // Two queues, not one: an order still sitting at Assigned has not
        // physically left the building, so signing it out to the courier
        // is a different job from settling what came back. Handover is
        // optional, so the second queue can still receive an order that
        // never appeared in the first.
        $awaitingHandover = $base()
            ->where('status', OrderStatus::Assigned)
            ->with(['customer', 'deliveryRepresentative', 'shippingCompany', 'payments'])
            ->latest('id')
            ->paginate(20, ['*'], 'handover')
            ->withQueryString()
            ->through($withOwed);

        $orders = $base()
            ->where('status', OrderStatus::OutForDelivery)
            ->with(['customer', 'deliveryRepresentative', 'shippingCompany', 'payments'])
            ->latest('id')
            ->paginate(20, ['*'], 'page')
            ->withQueryString()
            ->through($withOwed);

        // Everything a courier still owes money on: goods they took and
        // haven't fully paid for, and deliveries they came back short on.
        // The delivered ones have left every other queue, so without this
        // listing that money would only be findable by order number.
        $outstanding = $this->owingCourier($base())
            ->with(['customer', 'payments', 'deliveryRepresentative', 'shippingCompany'])
            ->latest('id')
            ->paginate(20, ['*'], 'outstanding')
            ->withQueryString()
            ->through($withOwed);

        return Inertia::render('Accounting/Index', [
            'awaitingHandover' => $awaitingHandover,
            'orders' => $orders,
            'outstanding' => $outstanding,
            'courierBalances' => $this->courierBalances($employee),
            'filters' => $courier,
            'dates' => $dates,
            'representatives' => DeliveryRepresentative::query()->orderBy('name')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->orderBy('name')->get(['id', 'name']),
            'treasuries' => Treasury::query()->where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * @param  Builder<Order>  $query
     * @param  array{representative_id: int|null, shipping_company_id: int|null}  $courier
     * @return Builder<Order>
     */
    private function forCourier(Builder $query, array $courier): Builder
    {
        return $query
            ->when(
                $courier['representative_id'] !== null,
                fn ($query) => $query->where('delivery_representative_id', $courier['representative_id']),
            )
            ->when(
                $courier['shipping_company_id'] !== null,
                fn ($query) => $query->where('shipping_company_id', $courier['shipping_company_id']),
            );
    }

    /**
     * Orders a courier still owes money on: out with them and not fully
     * paid for (the goods left with the courier, so the courier is on the
     * hook), or delivered and come back short.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private function owingCourier(Builder $query): Builder
    {
        return $query->where(fn (Builder $owing) => $owing
            ->where(fn (Builder $out) => $out
                ->where('status', OrderStatus::OutForDelivery)
                ->where('payment_status', '!=', PaymentStatus::Collected))
            ->orWhere('payment_status', PaymentStatus::PartiallyCollected));
    }

    /**
     * What each courier still owes — deliberately unfiltered by courier
     * and by date, because it is a running balance: a courier who took
     * goods last week and hasn't paid still owes today. `key` uses the
     * courier select's own encoding, so a row can set the filter.
     *
     * ponytail: sums in PHP over every owing order; that is the goods out
     * on the road plus the short deliveries, which stays small. Move the
     * sum into SQL (a join on the latest payment) if it runs to thousands.
     *
     * @return list<array{key: string, name: string, orders: int, with_courier: float, delivered: float, owed: float}>
     */
    private function courierBalances(Employee $employee): array
    {
        return $this->owingCourier(Order::query()->visibleTo($employee))
            ->with(['payments', 'deliveryRepresentative:id,name', 'shippingCompany:id,name'])
            ->get()
            ->groupBy(fn (Order $order) => $order->delivery_representative_id !== null
                ? 'representative:'.$order->delivery_representative_id
                : 'shipping_company:'.$order->shipping_company_id)
            ->map(function (Collection $group, string $key) {
                /** @var Order $first */
                $first = $group->first();
                $out = $group->filter(fn (Order $order) => $order->status === OrderStatus::OutForDelivery);
                $withCourier = round($out->sum(fn (Order $order) => $order->stillOwed()), 2);
                $owed = round($group->sum(fn (Order $order) => $order->stillOwed()), 2);

                return [
                    'key' => $key,
                    'name' => $first->deliveryRepresentative->name ?? $first->shippingCompany->name ?? '—',
                    'orders' => $group->count(),
                    // Goods still on the road, not yet paid for.
                    'with_courier' => $withCourier,
                    // Delivered, courier came back short.
                    'delivered' => round($owed - $withCourier, 2),
                    'owed' => $owed,
                ];
            })
            ->sortByDesc('owed')
            ->values()
            ->all();
    }

    /**
     * One courier, one sum of cash, several orders: split it oldest
     * first. See CollectFromCourierAction for why it is all or nothing.
     */
    public function collectFromCourier(Request $request, CollectFromCourierAction $action): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'distinct', 'exists:orders,id'],
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'collected_method' => ['required', Rule::enum(CollectedMethod::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $lines = $action->execute(
                $data['order_ids'],
                $request->user('employee'),
                Treasury::findOrFail($data['treasury_id']),
                CollectedMethod::from($data['collected_method']),
                (float) $data['amount'],
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $balance = round(array_sum(array_column($lines, 'balance')), 2);

        return back()->with('success', $balance > 0
            ? __(':amount collected across :count orders — :balance still owed.', [
                'amount' => number_format((float) $data['amount'], 2),
                'count' => count($lines),
                'balance' => number_format($balance, 2),
            ])
            : __(':amount collected — :count orders fully settled.', [
                'amount' => number_format((float) $data['amount'], 2),
                'count' => count($lines),
            ]));
    }

    /**
     * @return array{representative_id: int|null, shipping_company_id: int|null}
     */
    private function courierFilter(Request $request): array
    {
        $data = $request->validate([
            'representative_id' => ['nullable', 'integer', 'exists:delivery_representatives,id'],
            'shipping_company_id' => ['nullable', 'integer', 'exists:shipping_companies,id'],
        ]);

        // Cast: validate() hands back the raw query-string values, and the
        // screen compares these against numeric ids to decide which option
        // is selected — '6' would never match 6.
        return [
            'representative_id' => isset($data['representative_id']) ? (int) $data['representative_id'] : null,
            'shipping_company_id' => isset($data['shipping_company_id']) ? (int) $data['shipping_company_id'] : null,
        ];
    }

    /**
     * Settle everything one courier brought back, in one go.
     *
     * Each order is confirmed on its own terms — same Action, same
     * guards, same net figure — rather than one lump sum split between
     * them, so a single order that moved on since the page loaded is
     * skipped and named instead of corrupting the batch.
     */
    public function settleBulk(Request $request, ConfirmDeliveryResultAction $action): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'collected_method' => ['required', Rule::enum(CollectedMethod::class)],
        ]);

        $employee = $request->user('employee');
        $treasury = Treasury::findOrFail($data['treasury_id']);

        // visibleTo, not a bare whereIn: a data-scoped role must not be
        // able to settle an order it cannot see by posting its id.
        $orders = Order::query()
            ->visibleTo($employee)
            ->whereIn('id', $data['order_ids'])
            ->get();

        $settled = 0;
        $collected = 0.0;
        $skipped = [];

        foreach ($orders as $order) {
            try {
                // Measured from the payment, not netDueToTreasury(): a
                // courier who prepaid at handover only hands over the rest
                // now, and that rest is what this banks.
                $before = (float) $order->payments()->latest('id')->value('collected_amount');
                $action->confirmDelivered(
                    $order,
                    $employee,
                    $treasury,
                    CollectedMethod::from($data['collected_method']),
                );
                $settled++;
                $collected += (float) $order->payments()->latest('id')->value('collected_amount') - $before;
            } catch (RuntimeException $exception) {
                $skipped[] = $order->order_number;
            }
        }

        $redirect = redirect()->route('admin.accounting.index');

        if ($skipped !== []) {
            return $redirect->with('error', __(':settled settled — :skipped skipped, no longer out for delivery: :numbers', [
                'settled' => $settled,
                'skipped' => count($skipped),
                'numbers' => implode(', ', $skipped),
            ]));
        }

        return $redirect->with('success', __(':count orders settled, :amount banked.', [
            'count' => $settled,
            'amount' => number_format($collected, 2),
        ]));
    }

    /**
     * The courier has taken the goods. Records who signed them out and
     * when; moves no stock and no money.
     */
    public function handover(Request $request, Order $order, ConfirmHandoverAction $action): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $action->execute($order, $request->user('employee'), $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.accounting.show', $order)
            ->with('success', __('Order #:number is out for delivery.', ['number' => $order->order_number]));
    }

    public function show(Order $order): Response
    {
        // The geo levels too: Accounting settles what the courier actually
        // delivered, and "which area" is part of reading that back.
        $order->load([
            'customer',
            'items.productVariant.product',
            'payments.transactions.treasury:id,name',
            'payments.transactions.createdBy:id,full_name',
            // Who is carrying it. Accounting settles against whoever went
            // out with the cash, so the assignee and when it was handed
            // over belong on this screen.
            'deliveryRepresentative:id,name,phone',
            'shippingCompany:id,name,phone,contact_person',
            'deliveryAssignments' => fn ($query) => $query->latest('assigned_at')->limit(1)->with('assignedBy:id,full_name'),
            'shippingGovernorate:id,name',
            'shippingCity:id,name',
            'shippingDistrict:id,name',
            'shippingArea:id,name',
            // So the screen can say 'Replacement for #N' — the only thing
            // stopping an accountant collecting the full price of goods
            // the customer has already paid for once.
            'replacesOrder:id,order_number',
        ]);

        return Inertia::render('Accounting/Show', [
            'order' => $order,
            'treasuries' => Treasury::query()->where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function delivered(Request $request, Order $order, ConfirmDeliveryResultAction $action): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'collected_method' => ['required', Rule::enum(CollectedMethod::class)],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $action->confirmDelivered(
                $order,
                $request->user('employee'),
                Treasury::findOrFail($data['treasury_id']),
                CollectedMethod::from($data['collected_method']),
                isset($data['collected_amount']) ? (float) $data['collected_amount'] : null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.accounting.show', $order)->with('success', __('Order #:number marked Delivered.', ['number' => $order->order_number]));
    }

    public function returned(Request $request, Order $order, ConfirmDeliveryResultAction $action): RedirectResponse
    {
        $action->confirmReturnedAtDelivery($order, $request->user('employee'));

        return redirect()->route('admin.accounting.show', $order)->with('success', __('Order #:number marked Returned.', ['number' => $order->order_number]));
    }

    public function partiallyReturned(Request $request, Order $order, ConfirmDeliveryResultAction $action): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'collected_method' => ['required', Rule::enum(CollectedMethod::class)],
            'collected_amount' => ['required', 'numeric', 'min:0'],
            'kept_quantities' => ['required', 'array'],
            'kept_quantities.*' => ['integer', 'min:0'],
        ]);

        $action->confirmPartiallyReturned(
            $order,
            $request->user('employee'),
            Treasury::findOrFail($data['treasury_id']),
            CollectedMethod::from($data['collected_method']),
            (float) $data['collected_amount'],
            array_map('intval', $data['kept_quantities']),
        );

        return redirect()->route('admin.accounting.show', $order)->with('success', __('Order #:number marked Partially Returned.', ['number' => $order->order_number]));
    }

    /**
     * A later instalment on an order the courier came back short on. The
     * delivery result is already recorded; this only moves money, and
     * clears the order off the outstanding list once it settles.
     */
    public function collect(Request $request, Order $order, ConfirmDeliveryResultAction $action): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'collected_method' => ['required', Rule::enum(CollectedMethod::class)],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $order = $action->collectBalance(
                $order,
                $request->user('employee'),
                Treasury::findOrFail($data['treasury_id']),
                CollectedMethod::from($data['collected_method']),
                (float) $data['amount'],
            );
        } catch (RuntimeException $e) {
            return back()->with('error', __('That collection does not match what order #:number still owes.', [
                'number' => $order->order_number,
            ]));
        }

        return redirect()->route('admin.accounting.show', $order)->with('success', $order->payment_status === PaymentStatus::Collected
            ? __('Order #:number is fully paid.', ['number' => $order->order_number])
            : __('Payment recorded — order #:number still has a balance.', ['number' => $order->order_number]));
    }
}
