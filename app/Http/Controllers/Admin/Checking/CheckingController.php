<?php

namespace App\Http\Controllers\Admin\Checking;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Orders\MarkOrderBackorderAction;
use App\Actions\Orders\PostponeOrderAction;
use App\Actions\Orders\ResumeBackorderAction;
use App\Enums\OrderStatus;
use App\Exports\CheckingExport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/checking — Checking's work queue and single-order review
 * (spec Section 14). Every mutating action here is a thin call into a
 * Phase 3 Action; this controller's only job is request → Action →
 * Inertia redirect.
 */
class CheckingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:orders.view', only: ['index', 'show']),
            new Middleware('permission:checking.export', only: ['export']),
            new Middleware('permission:orders.status.update', only: ['confirm', 'postpone', 'cancel', 'backorder', 'resume']),
        ];
    }

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

    /**
     * The same work queue index() renders, as an .xlsx download.
     */
    public function export(Request $request): BinaryFileResponse
    {
        return (new CheckingExport($request->user('employee')))
            ->download('checking-'.now()->format('Y-m-d_His').'.xlsx');
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

        return back()->with('success', __('Order #:number confirmed.', ['number' => $order->order_number]));
    }

    public function postpone(Request $request, Order $order, PostponeOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['reason']);

        return back()->with('success', __('Order #:number postponed.', ['number' => $order->order_number]));
    }

    public function cancel(Request $request, Order $order, CancelOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['reason']);

        return back()->with('success', __('Order #:number cancelled.', ['number' => $order->order_number]));
    }

    public function backorder(Request $request, Order $order, MarkOrderBackorderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $action->execute($order, $request->user('employee'), $data['reason']);

        return back()->with('success', __('Order #:number marked as backordered.', ['number' => $order->order_number]));
    }

    public function resume(Request $request, Order $order, ResumeBackorderAction $action): RedirectResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'exists:warehouses,id']]);

        $action->execute($order, $request->user('employee'), Warehouse::findOrFail($data['warehouse_id']));

        return back()->with('success', __('Order #:number resumed — stock reserved.', ['number' => $order->order_number]));
    }
}
