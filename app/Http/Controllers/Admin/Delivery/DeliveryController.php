<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Actions\Orders\AssignDeliveryAction;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\Order;
use App\Models\ShippingCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * /admin/delivery (Section 14), as three screens rather than one board:
 * the queue of Confirmed orders waiting to be handed off, the assign form
 * for one of them, and the list of what is already out.
 */
class DeliveryController extends Controller implements HasMiddleware
{
    /**
     * Where the order is going, spelled out — every delivery screen shows
     * the four levels, since that is what decides who can take it.
     *
     * @var list<string>
     */
    private const SHIPPING_GEO = [
        'shippingGovernorate:id,name',
        'shippingCity:id,name',
        'shippingDistrict:id,name',
        'shippingArea:id,name',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:orders.view', only: ['index', 'orders']),
            new Middleware('permission:orders.assign', only: ['assignForm', 'assign', 'assignBulk']),
        ];
    }

    /**
     * The queue: Confirmed orders with nowhere to go yet. Carries the
     * assignee lists because the queue can hand off a whole batch at
     * once; a single order still gets its own form.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Delivery/Index', [
            'ready' => Order::query()
                ->visibleTo($request->user('employee'))
                ->where('status', OrderStatus::Confirmed)
                ->with(['customer', ...self::SHIPPING_GEO])
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'representatives' => DeliveryRepresentative::query()->where('status', 'active')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->where('status', 'active')->get(['id', 'name']),
        ]);
    }

    /** Everything already handed off — Assigned and Out for Delivery. */
    public function orders(Request $request): Response
    {
        return Inertia::render('Delivery/Orders', [
            'orders' => Order::query()
                ->visibleTo($request->user('employee'))
                ->whereIn('status', [OrderStatus::Assigned, OrderStatus::OutForDelivery])
                ->with(['customer', 'deliveryRepresentative', 'shippingCompany', ...self::SHIPPING_GEO])
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    /**
     * One order's assign form. The assignee lists are loaded here rather
     * than on the queue, so the queue is a plain listing again.
     */
    public function assignForm(Request $request, Order $order): Response
    {
        abort_unless($order->status === OrderStatus::Confirmed, 404);

        $order->load(['customer', ...self::SHIPPING_GEO]);

        return Inertia::render('Delivery/Assign', [
            'order' => $order,
            'representatives' => DeliveryRepresentative::query()->where('status', 'active')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->where('status', 'active')->get(['id', 'name']),
        ]);
    }

    public function assign(Request $request, Order $order, AssignDeliveryAction $action): RedirectResponse
    {
        $data = $request->validate([
            'assignment_type' => ['required', Rule::enum(DeliveryAssignmentType::class)],
            'assignee_id' => ['required', 'integer'],
        ]);

        [$type, $assignee] = $this->resolveAssignee($data);

        $action->execute($order, $request->user('employee'), $type, $assignee);

        // Back to the queue, not back to the form — that order has left it.
        return redirect()
            ->route('admin.delivery.index')
            ->with('success', __('Order #:number assigned.', ['number' => $order->order_number]));
    }

    /**
     * A whole batch to one representative or company — a full van's round
     * in one go, rather than the same form N times.
     *
     * Each order is assigned in its own transaction (the Action opens
     * one), so an order somebody else moved out of Confirmed in the
     * meantime is skipped and reported rather than failing the batch.
     */
    public function assignBulk(Request $request, AssignDeliveryAction $action): RedirectResponse
    {
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
            'assignment_type' => ['required', Rule::enum(DeliveryAssignmentType::class)],
            'assignee_id' => ['required', 'integer'],
        ]);

        [$type, $assignee] = $this->resolveAssignee($data);
        $employee = $request->user('employee');

        // visibleTo, not a bare whereIn: a data-scoped role must not be
        // able to assign an order it cannot see by posting its id.
        $orders = Order::query()
            ->visibleTo($employee)
            ->whereIn('id', $data['order_ids'])
            ->get();

        $assigned = 0;
        $skipped = [];
        foreach ($orders as $order) {
            try {
                $action->execute($order, $employee, $type, $assignee);
                $assigned++;
            } catch (RuntimeException $e) {
                $skipped[] = $order->order_number;
            }
        }

        $redirect = redirect()->route('admin.delivery.index');

        if ($skipped !== []) {
            return $redirect->with('error', __(':assigned assigned — :skipped skipped, no longer awaiting assignment: :numbers', [
                'assigned' => $assigned,
                'skipped' => count($skipped),
                'numbers' => implode(', ', $skipped),
            ]));
        }

        return $redirect->with('success', __(':count orders assigned to :name.', [
            'count' => $assigned,
            'name' => $assignee->name,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: DeliveryAssignmentType, 1: DeliveryRepresentative|ShippingCompany}
     */
    private function resolveAssignee(array $data): array
    {
        $type = DeliveryAssignmentType::from($data['assignment_type']);

        return [
            $type,
            $type === DeliveryAssignmentType::Representative
                ? DeliveryRepresentative::findOrFail($data['assignee_id'])
                : ShippingCompany::findOrFail($data['assignee_id']),
        ];
    }
}
