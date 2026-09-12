<?php

namespace App\Http\Controllers\Admin\Checking;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Orders\MarkOrderBackorderAction;
use App\Actions\Orders\PostponeOrderAction;
use App\Actions\Orders\ResumeBackorderAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/checking — Checking's work queue and single-order review
 * (spec Section 14). Every mutating action here is a thin call into a
 * Phase 3 Action; this controller's only job is request → Action →
 * Inertia redirect.
 */
class CheckingController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->visibleTo($request->user('employee'))
            ->whereIn('status', [OrderStatus::New, OrderStatus::Checking, OrderStatus::Postponed, OrderStatus::Backorder])
            ->with('customer')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Checking/Index', ['orders' => $orders]);
    }

    public function show(Request $request, Order $order): Response
    {
        $order->load(['customer', 'items.productVariant.product', 'statusHistory.changedBy']);

        return Inertia::render('Checking/Show', [
            'order' => $order,
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function confirm(Request $request, Order $order, ConfirmOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['notes'] ?? null);

        return back()->with('success', "Order #{$order->order_number} confirmed.");
    }

    public function postpone(Request $request, Order $order, PostponeOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['reason']);

        return back()->with('success', "Order #{$order->order_number} postponed.");
    }

    public function cancel(Request $request, Order $order, CancelOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['reason']);

        return back()->with('success', "Order #{$order->order_number} cancelled.");
    }

    public function backorder(Request $request, Order $order, MarkOrderBackorderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['reason']);

        return back()->with('success', "Order #{$order->order_number} marked as backordered.");
    }

    public function resume(Request $request, Order $order, ResumeBackorderAction $action): RedirectResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'exists:warehouses,id']]);

        $action->execute($order, $request->user('employee'), Warehouse::findOrFail($data['warehouse_id']));

        return back()->with('success', "Order #{$order->order_number} resumed — stock reserved.");
    }
}
