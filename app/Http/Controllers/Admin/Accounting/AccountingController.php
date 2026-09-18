<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Enums\CollectedMethod;
use App\Enums\OrderStatus;
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
            new Middleware('permission:orders.confirm_delivery', only: ['delivered', 'returned', 'partiallyReturned']),
        ];
    }

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->visibleTo($request->user('employee'))
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::OutForDelivery])
            ->with(['customer', 'deliveryRepresentative', 'shippingCompany'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Accounting/Index', ['orders' => $orders]);
    }

    public function show(Order $order): Response
    {
        $order->load(['customer', 'items.productVariant.product', 'payments']);

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

        return redirect()->route('admin.accounting.index')->with('success', __('Order #:number marked Delivered.', ['number' => $order->order_number]));
    }

    public function returned(Request $request, Order $order, ConfirmDeliveryResultAction $action): RedirectResponse
    {
        $action->confirmReturnedAtDelivery($order, $request->user('employee'));

        return redirect()->route('admin.accounting.index')->with('success', __('Order #:number marked Returned.', ['number' => $order->order_number]));
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

        return redirect()->route('admin.accounting.index')->with('success', __('Order #:number marked Partially Returned.', ['number' => $order->order_number]));
    }
}
