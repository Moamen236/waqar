<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmHandoverAction;
use App\Enums\CollectedMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\Order;
use App\Models\ShippingCompany;
use App\Models\Treasury;
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
            new Middleware('permission:orders.confirm_delivery', only: ['handover', 'delivered', 'returned', 'partiallyReturned', 'collect', 'settleBulk']),
        ];
    }

    public function index(Request $request): Response
    {
        // Two queues, not one: an order still sitting at Assigned has not
        // physically left the building, so signing it out to the courier
        // is a different job from settling what came back. Handover is
        // optional, so the second queue can still receive an order that
        // never appeared in the first.
        $awaitingHandover = Order::query()
            ->visibleTo($request->user('employee'))
            ->where('status', OrderStatus::Assigned)
            ->with(['customer', 'deliveryRepresentative', 'shippingCompany'])
            ->latest('id')
            ->paginate(20, ['*'], 'handover')
            ->withQueryString();

        // Settlement happens courier by courier: one of them turns up with
        // a bag of cash for everything they carried, so the queue filters
        // to that person and totals what they owe.
        $courier = $this->courierFilter($request);

        $orders = Order::query()
            ->visibleTo($request->user('employee'))
            ->where('status', OrderStatus::OutForDelivery)
            ->when(
                $courier['representative_id'] !== null,
                fn ($query) => $query->where('delivery_representative_id', $courier['representative_id']),
            )
            ->when(
                $courier['shipping_company_id'] !== null,
                fn ($query) => $query->where('shipping_company_id', $courier['shipping_company_id']),
            )
            ->with(['customer', 'deliveryRepresentative', 'shippingCompany'])
            ->latest('id')
            ->paginate(20, ['*'], 'page')
            ->withQueryString();

        // Delivered, but the courier came back short. These have left
        // every other queue, so without this listing the outstanding
        // money would only be findable by remembering the order number.
        $outstanding = Order::query()
            ->visibleTo($request->user('employee'))
            ->where('payment_status', PaymentStatus::PartiallyCollected)
            ->with(['customer', 'payments'])
            ->latest('id')
            ->paginate(20, ['*'], 'outstanding')
            ->withQueryString();

        return Inertia::render('Accounting/Index', [
            'awaitingHandover' => $awaitingHandover,
            'orders' => $orders,
            'outstanding' => $outstanding,
            'filters' => $courier,
            'representatives' => DeliveryRepresentative::query()->orderBy('name')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->orderBy('name')->get(['id', 'name']),
            'treasuries' => Treasury::query()->where('is_active', true)->get(['id', 'name', 'type']),
        ]);
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
                $action->confirmDelivered(
                    $order,
                    $employee,
                    $treasury,
                    CollectedMethod::from($data['collected_method']),
                );
                $settled++;
                $collected += $order->netDueToTreasury();
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

        $action->confirmDelivered(
            $order,
            $request->user('employee'),
            Treasury::findOrFail($data['treasury_id']),
            CollectedMethod::from($data['collected_method']),
            isset($data['collected_amount']) ? (float) $data['collected_amount'] : null,
        );

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
