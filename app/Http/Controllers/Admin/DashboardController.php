<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Treasury;
use App\Models\WarehouseInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin — the operations overview.
 *
 * Role-scoped *widget* content (spec Question 18) is still deferred by the
 * spec itself ("screen layout/components... designed in a separate
 * follow-up"). What this does provide is the figures the admin already
 * has the data for, so the Larkon dashboard layout has something real to
 * render: counts and sums read straight off the existing tables.
 *
 * Two rules this stays inside:
 *
 * - **Read-only.** Every query here is a count/sum/select. Nothing on
 *   this screen writes, and no Action is invoked.
 * - **Permission-gated per tile, not per page.** The route itself is open
 *   to any authenticated employee (it is the post-login landing page), so
 *   each block is computed only when the viewer holds the same permission
 *   that guards the module it summarises — a Checking employee without
 *   `treasury.view` never has a treasury balance computed, let alone
 *   serialised. Tiles the viewer cannot see are absent from the props
 *   rather than zeroed, and the page renders the grid it is given.
 *
 * Order figures go through `Order::visibleTo()`, the same scope every
 * order listing already uses, so a Customer Service Team Leader's
 * dashboard counts the same orders their queue does.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $employee = $request->user('employee');

        $visible = fn () => Order::query()->visibleTo($employee);

        // Each tile is gated by the screen it summarises and links to, so a
        // count never shows up for a queue the viewer cannot open.
        return Inertia::render('Dashboard', [
            'stats' => [
                'orders' => $employee->can('orders.view') ? $this->orderStats($request) : null,
                'checking' => $employee->can('checking.view')
                    ? $visible()->whereIn('status', [OrderStatus::New, OrderStatus::Checking])->count()
                    : null,
                'delivery' => $employee->can('delivery.view')
                    ? $visible()->whereIn('status', [OrderStatus::Assigned, OrderStatus::OutForDelivery])->count()
                    : null,
                'catalog' => $employee->can('products.view') ? $this->catalogStats() : null,
                'customers' => $employee->can('customers.view') ? Customer::query()->count() : null,
                'returns' => $employee->can('returns.view') ? $this->openReturns() : null,
                'treasury' => $employee->can('treasury.view') ? $this->treasuryBalance() : null,
            ],
            'latestOrders' => $employee->can('orders.view') ? $this->latestOrders($request) : null,
            'lowestStock' => $employee->can('inventory.view') ? $this->lowestStock() : null,
        ]);
    }

    /**
     * @return array<string, int|string>
     */
    private function orderStats(Request $request): array
    {
        $visible = fn () => Order::query()->visibleTo($request->user('employee'));

        return [
            'today' => $visible()->whereDate('created_at', today())->count(),
            // Delivered but not yet reconciled by Accounting: the queue
            // /admin/accounting works from.
            'delivered_this_month' => $visible()
                ->where('status', OrderStatus::Delivered)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            // Revenue counts Delivered orders only — the same point in the
            // lifecycle at which stock actually deducts (Section 07). An
            // order that is merely confirmed is not revenue.
            // Net of shipping: the courier keeps that at the door, so it
            // is never company revenue. Summing total here would report
            // money that cannot be found in any treasury.
            'revenue_this_month' => (string) $visible()
                ->where('status', OrderStatus::Delivered)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum(DB::raw('total - shipping_amount')),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function catalogStats(): array
    {
        return [
            'active_products' => Product::query()->where('status', true)->count(),
            'total_products' => Product::query()->count(),
        ];
    }

    private function openReturns(): int
    {
        return OrderReturn::query()
            ->whereIn('status', [
                ReturnStatus::Requested,
                ReturnStatus::Approved,
                ReturnStatus::Received,
                ReturnStatus::Inspected,
            ])
            ->count();
    }

    private function treasuryBalance(): string
    {
        return (string) Treasury::query()->sum('current_balance');
    }

    /**
     * @return Collection<int, Order>
     */
    private function latestOrders(Request $request): Collection
    {
        return Order::query()
            ->visibleTo($request->user('employee'))
            ->with('customer:id,name')
            ->latest('id')
            ->limit(8)
            ->get(['id', 'order_number', 'customer_id', 'status', 'total', 'created_at']);
    }

    /**
     * The variants closest to running out, ranked by available stock.
     *
     * Deliberately a ranking rather than a "below threshold" alert: there
     * is no reorder-point column on `warehouse_inventory` (Section 24), so
     * any threshold would be a number invented here rather than one the
     * business set.
     *
     * Returned as a plain list rather than a Collection: Collection's
     * TValue template is invariant, so a mapped shape never satisfies its
     * own declared type, and Inertia serialises either identically.
     *
     * @return list<array{id: int, sku: string|null, product: string|null, warehouse: string|null, available: int}>
     */
    private function lowestStock(): array
    {
        return WarehouseInventory::query()
            ->with(['productVariant.product:id,name', 'warehouse:id,name'])
            ->selectRaw('*, (quantity - reserved_quantity) as available_stock')
            ->orderBy('available_stock')
            ->limit(6)
            ->get()
            ->map(fn (WarehouseInventory $row) => [
                'id' => $row->id,
                'sku' => $row->productVariant?->sku,
                'product' => $row->productVariant?->product?->name,
                'warehouse' => $row->warehouse?->name,
                'available' => $row->available,
            ])
            ->values()
            ->all();
    }
}
