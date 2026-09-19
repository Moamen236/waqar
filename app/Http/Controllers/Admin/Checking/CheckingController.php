<?php

namespace App\Http\Controllers\Admin\Checking;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Orders\MarkOrderBackorderAction;
use App\Actions\Orders\PostponeOrderAction;
use App\Actions\Orders\ResumeBackorderAction;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exports\CheckingExport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
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
            'stock' => $order->status === OrderStatus::Backorder ? $this->stockCheck($order) : null,
        ]);
    }

    /**
     * What Resume would find in the main warehouse if it ran now. Resume
     * reserves there and nowhere else, so the page can say up front
     * whether every line is coverable instead of letting
     * ResumeBackorderAction throw its way to an error page.
     *
     * @return array{warehouse: string|null, items: list<array{name: string, required: int, available: int, tracked: bool}>, can_resume: bool}
     */
    private function stockCheck(Order $order): array
    {
        $warehouse = Warehouse::main();

        $available = $warehouse === null ? collect() : WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $order->items->pluck('product_variant_id'))
            ->get()
            ->keyBy('product_variant_id');

        $items = $order->items->map(function (OrderItem $item) use ($available) {
            $inventory = $available->get($item->product_variant_id);

            return [
                'name' => $item->product_name_snapshot,
                'required' => $item->quantity,
                'available' => $inventory ? $inventory->quantity - $inventory->reserved_quantity : 0,
                // An Advertisement product carries no inventory at all, so
                // it can't be reserved however the numbers read (Q14).
                // data_get, not a nullsafe chain: a soft-deleted product
                // nulls the relation, which the BelongsTo type doesn't say.
                'tracked' => (bool) data_get($item, 'productVariant.product.inventory_tracking_enabled', false),
            ];
        })->all();

        return [
            'warehouse' => $warehouse?->name,
            'items' => $items,
            'can_resume' => $warehouse !== null
                && collect($items)->every(fn (array $i) => $i['tracked'] && $i['available'] >= $i['required']),
        ];
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

    /**
     * No warehouse picker — Resume always reserves from the main
     * warehouse, the same one admin order-create uses. The stock check
     * show() renders gates the button, but this catch is the real guard:
     * stock can be taken by another order between the page load and the
     * click, and that used to surface as a 500.
     */
    public function resume(Request $request, Order $order, ResumeBackorderAction $action): RedirectResponse
    {
        $warehouse = Warehouse::main();

        if ($warehouse === null) {
            return back()->with('error', __('There is no active warehouse to reserve stock from.'));
        }

        try {
            $action->execute($order, $request->user('employee'), $warehouse);
        } catch (InsufficientStockException|RuntimeException $e) {
            return back()->with('error', __('Order #:number cannot be resumed yet — :warehouse does not have every item in stock.', [
                'number' => $order->order_number,
                'warehouse' => $warehouse->name,
            ]));
        }

        return back()->with('success', __('Order #:number resumed — stock reserved.', ['number' => $order->order_number]));
    }
}
