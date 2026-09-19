<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Enums\CollectedMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
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
            new Middleware('permission:orders.confirm_delivery', only: ['delivered', 'returned', 'partiallyReturned', 'collect']),
        ];
    }

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->visibleTo($request->user('employee'))
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::OutForDelivery])
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
            'orders' => $orders,
            'outstanding' => $outstanding,
        ]);
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
