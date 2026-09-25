<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Actions\Orders\AssignDeliveryAction;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\Order;
use App\Models\ShippingCompany;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * /admin/delivery (Section 14): one board over everything waiting for or
 * already with a courier, plus the assign form for a single order.
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

    /**
     * What the board lists: waiting for a courier, or already with one.
     *
     * @var list<OrderStatus>
     */
    private const BOARD_STATUSES = [
        OrderStatus::Confirmed,
        OrderStatus::Assigned,
        OrderStatus::OutForDelivery,
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:delivery.view', only: ['index']),
            new Middleware('permission:delivery.assign', only: ['assignForm', 'assign', 'assignBulk']),
            new Middleware('permission:delivery.move', only: ['reassign']),
        ];
    }

    /**
     * The board: every order Delivery still has a hand in — Confirmed ones
     * waiting to be handed off, and Assigned / Out for Delivery ones on the
     * road that may need moving to another courier. One listing, narrowed
     * by the same Order::filtered() the order book uses.
     *
     * No default date window, unlike the order book: this is a work queue,
     * and yesterday's unassigned order is exactly the one that must not
     * drop off the screen.
     */
    public function index(Request $request): Response
    {
        $employee = $request->user('employee');
        $boardStatuses = array_map(fn (OrderStatus $s) => $s->value, self::BOARD_STATUSES);

        $filters = $request->validate([
            'status' => ['nullable', Rule::in($boardStatuses)],
            'q' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'string', 'max:255'],
            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'representative_id' => ['nullable', 'integer', 'exists:delivery_representatives,id'],
            'shipping_company_id' => ['nullable', 'integer', 'exists:shipping_companies,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'qty_min' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'qty_max' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $board = fn () => Order::query()
            ->visibleTo($employee)
            ->whereIn('status', $boardStatuses);

        // Counted off everything *but* the status filter, so the tabs say
        // how many of each the other filters leave — clicking one never
        // surprises with a different number than its badge.
        $counts = $board()
            ->filtered(Arr::except($filters, 'status'))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('Delivery/Index', [
            'orders' => $board()
                ->filtered($filters)
                ->with(['customer', 'deliveryRepresentative:id,name', 'shippingCompany:id,name', ...self::SHIPPING_GEO])
                ->latest('id')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            'counts' => collect($boardStatuses)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)]),
            'geoTree' => GeoTree::tree(),
            'representatives' => DeliveryRepresentative::query()->where('status', 'active')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->where('status', 'active')->get(['id', 'name']),
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
     * Hand an order already on the road to a different courier. The status
     * does not move — only who is carrying it — and the previous assignment
     * stays in `delivery_assignments` so a missing parcel is still
     * traceable to whoever had it.
     */
    public function reassign(Request $request, Order $order, AssignDeliveryAction $action): RedirectResponse
    {
        $data = $request->validate([
            'assignment_type' => ['required', Rule::enum(DeliveryAssignmentType::class)],
            'assignee_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        // visibleTo as a guard, not a filter: a data-scoped role must not
        // reassign an order it cannot see by posting its id.
        abort_unless(
            Order::query()->visibleTo($request->user('employee'))->whereKey($order->getKey())->exists(),
            403,
        );

        [$type, $assignee] = $this->resolveAssignee($data);

        try {
            $action->reassign($order, $request->user('employee'), $type, $assignee, $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Order #:number moved to :name.', [
            'number' => $order->order_number,
            'name' => $assignee->name,
        ]));
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
