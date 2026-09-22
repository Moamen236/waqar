<?php

namespace App\Http\Controllers\Admin\Checking;

use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Orders\MarkOrderBackorderAction;
use App\Actions\Orders\PostponeOrderAction;
use App\Actions\Orders\ResumeBackorderAction;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exports\CheckingExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
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
            'stock' => $this->stockCheck($order),
        ]);
    }

    /**
     * What the main warehouse holds for this order right now, per line.
     *
     * Two buttons read this and they need different numbers.
     * ResumeBackorderAction reserves every line from scratch, so it needs
     * *free* stock. Confirm only needs the order to be covered — and an
     * order reserved at creation already is, except `quantity -
     * reserved_quantity` subtracts that order's own hold straight back
     * out, which would read as a shortage on a perfectly healthy order and
     * lock Confirm on the entire queue. So `reserved` carries what this
     * order itself holds and only Confirm adds it back.
     *
     * `reserved` is the ledger's claim capped by what the inventory row
     * actually holds back, not the claim alone. The two only diverge when
     * the shelf was emptied out from under a live reservation, and there
     * the shelf is what Confirm has to believe.
     *
     * @return array{warehouse: string|null, items: list<array{name: string, required: int, available: int, reserved: int, tracked: bool}>, can_resume: bool, can_confirm: bool}
     */
    private function stockCheck(Order $order): array
    {
        $warehouse = Warehouse::main();

        $available = $warehouse === null ? collect() : WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_variant_id', $order->items->pluck('product_variant_id'))
            ->get()
            ->keyBy('product_variant_id');

        // Release records a negative quantity, so the sum is the net hold.
        $held = InventoryMovement::query()
            ->where('reference_type', $order->getMorphClass())
            ->where('reference_id', $order->getKey())
            ->whereIn('type', [InventoryMovementType::Reservation, InventoryMovementType::Release])
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, SUM(quantity) as net')
            ->pluck('net', 'product_variant_id');

        $items = $order->items->map(function (OrderItem $item) use ($available, $held) {
            $inventory = $available->get($item->product_variant_id);

            return [
                'name' => $item->product_name_snapshot,
                'required' => $item->quantity,
                'available' => $inventory ? $inventory->quantity - $inventory->reserved_quantity : 0,
                'reserved' => $inventory
                    ? max(0, min((int) $held->get($item->product_variant_id, 0), $inventory->reserved_quantity))
                    : 0,
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
            // An Advertisement line is unstocked by design (Section 05) and
            // must not block Confirm — Backorder is where it gets caught,
            // after Confirm, which is the flow Question 14 settled on.
            'can_confirm' => collect($items)->every(
                fn (array $i) => ! $i['tracked'] || $i['required'] <= $i['available'] + $i['reserved'],
            ),
        ];
    }

    public function confirm(Request $request, Order $order, ConfirmOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        // The disabled button is the UI half of this; this is the guard.
        // Another order can take the stock between the page load and the
        // click, exactly as it can for Resume below.
        $order->loadMissing('items.productVariant.product');
        $stock = $this->stockCheck($order);

        if (! $stock['can_confirm']) {
            return back()->with('error', __('Order #:number cannot be confirmed — :warehouse does not have every item in stock.', [
                'number' => $order->order_number,
                'warehouse' => $stock['warehouse'] ?? __('The warehouse'),
            ]));
        }

        return $this->attempt(
            $order,
            fn () => $action->execute($order, $request->user('employee'), $data['notes'] ?? null),
            __('Order #:number confirmed.', ['number' => $order->order_number]),
        );
    }

    public function postpone(Request $request, Order $order, PostponeOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(
            $order,
            fn () => $action->execute($order, $request->user('employee'), $data['reason']),
            __('Order #:number postponed.', ['number' => $order->order_number]),
        );
    }

    public function cancel(Request $request, Order $order, CancelOrderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(
            $order,
            fn () => $action->execute($order, $request->user('employee'), $data['reason']),
            __('Order #:number cancelled.', ['number' => $order->order_number]),
        );
    }

    public function backorder(Request $request, Order $order, MarkOrderBackorderAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(
            $order,
            fn () => $action->execute($order, $request->user('employee'), $data['reason']),
            __('Order #:number marked as backordered.', ['number' => $order->order_number]),
        );
    }

    /**
     * Run one transition and turn its "not legal from this status" guard
     * into a flash message. The Actions card only offers the transitions
     * the current status allows, but a stale tab — or two people on the
     * same order — can still post one that has since become illegal, and
     * that used to surface as a 500.
     */
    private function attempt(Order $order, callable $transition, string $success): RedirectResponse
    {
        try {
            $transition();
        } catch (RuntimeException $e) {
            return back()->with('error', __('Order #:number is no longer at a status that allows that.', [
                'number' => $order->order_number,
            ]));
        }

        return back()->with('success', $success);
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
