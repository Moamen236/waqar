<?php

namespace App\Http\Controllers\Admin\Returns;

use App\Actions\Returns\AcceptReturnShippingFeeAction;
use App\Actions\Returns\ApproveReturnAction;
use App\Actions\Returns\AssignReturnPickupAction;
use App\Actions\Returns\CheckReturnAction;
use App\Actions\Returns\CreateReplacementOrderAction;
use App\Actions\Returns\ReceiveReturnAction;
use App\Actions\Returns\RefundReturnAction;
use App\Actions\Returns\RequestReturnAction;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderStatus;
use App\Enums\RefundMethod;
use App\Exceptions\InsufficientStockException;
use App\Exports\ReturnsExport;
use App\Http\Controllers\Controller;
use App\Models\DeliveryRepresentative;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\ShippingCompany;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Support\DateRangeFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /admin/returns (Warehouse Manager + Accounting, both hold
 * returns.manage) — the post-delivery customer-initiated workflow
 * (Section 12): Requested → Approved → Received → Inspected → Refunded.
 * An at-delivery refusal is Accounting's confirmReturnedAtDelivery()
 * instead, already on the Accounting screen (Phase 4). No storefront
 * exists yet for a customer to file one themselves, so "create" here is
 * staff filing a return on the customer's behalf.
 */
class ReturnController extends Controller implements HasMiddleware
{
    /**
     * Not CRUD-shaped: approve/receive/refund are three sequential
     * business transitions on the same return, not create/update/delete
     * of a record, so split by transition name. returns.create covers
     * filing one on a customer's behalf + recording their shipping-fee
     * consent + viewing the list (Customer Service's job, same convention
     * as orders.create).
     */
    public static function middleware(): array
    {
        return [
            // returns.check reaches the list and one return too: Checking
            // cannot phone a customer about a return it is not allowed to
            // open. It gets no create/store — verifying a return is not
            // the same as filing one.
            new Middleware('permission:returns.create|returns.check', only: ['index', 'show']),
            new Middleware('permission:returns.create', only: ['create', 'store', 'acceptShippingFee']),
            new Middleware('permission:returns.check', only: ['check']),
            new Middleware('permission:returns.export', only: ['export']),
            new Middleware('permission:returns.approve', only: ['approve']),
            new Middleware('permission:returns.receive', only: ['receive', 'assignPickup']),
            new Middleware('permission:returns.refund', only: ['refund', 'replace']),
        ];
    }

    public function index(Request $request): Response
    {
        $range = DateRangeFilter::fromRequest($request);

        $returns = OrderReturn::query()
            // Same Customer Service scoping the order book uses: an agent
            // sees returns on their own orders, a Team Leader their team's.
            ->visibleTo($request->user('employee'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->tap(fn ($query) => DateRangeFilter::apply($query, $range))
            ->with(['order:id,order_number,total', 'customer:id,name,phone'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Returns/Index', [
            'returns' => $returns,
            'status' => $request->query('status'),
            'filters' => $range,
        ]);
    }

    /**
     * The same rows index() renders — same status filter, same date
     * window — as an .xlsx download.
     */
    public function export(Request $request): BinaryFileResponse
    {
        return (new ReturnsExport(
            (string) $request->query('status', ''),
            $request->user('employee'),
            DateRangeFilter::fromRequest($request),
        ))->download('returns-'.now()->format('Y-m-d_His').'.xlsx');
    }

    public function create(Request $request): Response
    {
        $order = null;
        if ($request->filled('order_number')) {
            $order = Order::query()
                // Scoped, so an agent cannot file a return against an
                // order outside their own book by typing its number.
                ->visibleTo($request->user('employee'))
                ->where('order_number', $request->query('order_number'))
                ->where('status', OrderStatus::Delivered)
                // `customer` is rendered on this screen — without it the
                // page threw "Cannot read properties of undefined" and
                // rendered blank, which is not visible in the payload
                // audit because the prop is simply absent rather than
                // wrongly shaped.
                ->with(['customer', 'items.productVariant.product'])
                ->first();
        }

        return Inertia::render('Returns/Create', [
            'order' => $order,
            'orderNumber' => $request->query('order_number'),
            'reasons' => ReturnReason::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request, RequestReturnAction $action): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'exists:orders,id'],
            'primary_reason_id' => ['required', 'exists:return_reasons,id'],
            'customer_notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.reason_id' => ['nullable', 'exists:return_reasons,id'],
        ]);

        // Same scope the create screen resolves through — a valid
        // order_id from outside the agent's book is still refused.
        $order = Order::query()
            ->visibleTo($request->user('employee'))
            ->findOrFail($data['order_id']);

        $return = $action->execute(
            $order,
            $order->customer,
            $data['items'],
            $data['primary_reason_id'],
            $data['customer_notes'] ?? null,
        );

        return redirect()->route('admin.returns.show', $return)->with('success', __('Return request recorded.'));
    }

    public function show(Request $request, OrderReturn $return): Response
    {
        // A guard, not a filter: without it an agent could read another
        // agent's return by guessing its id.
        abort_unless(
            OrderReturn::query()->visibleTo($request->user('employee'))->whereKey($return->getKey())->exists(),
            403,
        );

        $return->load([
            'order.customer:id,name,email,phone',
            'order.items.productVariant.product:id,name',
            'order.shippingGovernorate:id,name',
            'order.shippingCity:id,name',
            'order.shippingDistrict:id,name',
            'order.shippingArea:id,name',
            'order.shippingCompany:id,name',
            'order.deliveryRepresentative:id,name,phone',
            'items.orderItem.productVariant.product',
            'reason',
            'refund',
            'checkedBy:id,full_name',
            'deliveryRepresentative:id,name',
            'shippingCompany:id,name',
        ]);

        return Inertia::render('Returns/Show', [
            'return' => $return,
            // No picker — a single-store operation always restocks into
            // the main warehouse, shown read-only (same arrangement as
            // admin order-create).
            'warehouse' => Warehouse::main()?->only(['id', 'name']),
            // What can be sent as a replacement. Same flat list the admin
            // order-create screen uses — a swap is chosen from the whole
            // catalogue, not just the returned product's siblings.
            'variants' => ProductVariant::query()
                ->where('status', true)
                ->whereHas('product')
                ->with('product:id,name')
                ->get()
                ->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'label' => $variant->product->getTranslation('name', app()->getLocale()).' — '.$variant->sku,
                    'price' => (float) $variant->effectivePrice(),
                ])
                ->values(),
            // Who can be sent to collect the goods coming back.
            'representatives' => DeliveryRepresentative::query()->where('status', 'active')->get(['id', 'name']),
            'shippingCompanies' => ShippingCompany::query()->where('status', 'active')->get(['id', 'name']),
            'treasuries' => Treasury::query()->where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function acceptShippingFee(Request $request, OrderReturn $return, AcceptReturnShippingFeeAction $action): RedirectResponse
    {
        $data = $request->validate(['return_shipping_fee' => ['required', 'numeric', 'min:0']]);

        // Recorded against the customer's own consent (Question 6) — an
        // employee is the one clicking this, but only ever to record
        // consent actually obtained from the customer (e.g. by phone),
        // never to approve a fee on the customer's behalf.
        $action->execute($return, $return->order->customer, (float) $data['return_shipping_fee']);

        return back()->with('success', __('Return shipping fee recorded as accepted.'));
    }

    /**
     * Name (or change) who collects the goods coming back. Returns
     * carried no assignee at all until now — the warehouse restocked
     * whenever someone pressed Received and nothing said who had been
     * sent to fetch the parcel.
     */
    public function assignPickup(Request $request, OrderReturn $return, AssignReturnPickupAction $action): RedirectResponse
    {
        $data = $request->validate([
            'assignment_type' => ['required', Rule::enum(DeliveryAssignmentType::class)],
            'assignee_id' => ['required', 'integer'],
        ]);

        $assignee = $data['assignment_type'] === DeliveryAssignmentType::Representative->value
            ? DeliveryRepresentative::findOrFail($data['assignee_id'])
            : ShippingCompany::findOrFail($data['assignee_id']);

        try {
            $action->execute(
                $return,
                $request->user('employee'),
                DeliveryAssignmentType::from($data['assignment_type']),
                $assignee,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Collection assigned to :name.', ['name' => $assignee->name]));
    }

    /**
     * Send a different item instead of refunding. Creates a new order
     * linked to the one being replaced; the customer pays the shipping
     * and any price difference.
     */
    public function replace(Request $request, OrderReturn $return, CreateReplacementOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $order = $action->execute($return, $data['items'], $request->user('employee'));
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (InsufficientStockException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.orders.show', $order)
            ->with('success', __('Replacement order #:number created.', ['number' => $order->order_number]));
    }

    public function approve(Request $request, OrderReturn $return, ApproveReturnAction $action): RedirectResponse
    {
        $action->execute($return, $request->user('employee'));

        return back()->with('success', __('Return approved.'));
    }

    /**
     * Checking's phone call: confirm the reason, reschedule if the
     * customer could not be reached, or cancel it outright. One endpoint
     * for the three outcomes — they are one decision taken on one call,
     * not three unrelated transitions.
     */
    public function check(Request $request, OrderReturn $return, CheckReturnAction $action): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['confirm', 'reschedule', 'cancel'])],
            // Required on a cancel: rejecting a customer's return without
            // recording why is how a dispute becomes unanswerable.
            'notes' => [Rule::requiredIf($request->input('outcome') === 'cancel'), 'nullable', 'string', 'max:500'],
        ]);

        try {
            match ($data['outcome']) {
                'confirm' => $action->confirm($return, $request->user('employee'), $data['notes'] ?? null),
                'reschedule' => $action->reschedule($return, $request->user('employee'), $data['notes'] ?? null),
                'cancel' => $action->cancel($return, $request->user('employee'), $data['notes']),
            };
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('Return checked.'));
    }

    public function receive(Request $request, OrderReturn $return, ReceiveReturnAction $action): RedirectResponse
    {
        // The request never decides where a restock lands: received items
        // always go back into the main warehouse, so any warehouse_id
        // supplied by the client is ignored rather than validated.
        $warehouse = Warehouse::main();

        abort_if($warehouse === null, 422, __('No active warehouse is configured.'));

        $action->execute($return, $request->user('employee'), $warehouse);

        return back()->with('success', __('Return received and restocked.'));
    }

    public function refund(Request $request, OrderReturn $return, RefundReturnAction $action): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'method' => ['required', Rule::enum(RefundMethod::class)],
            'reference_number' => ['required', 'string', 'max:255'],
        ]);

        $action->execute(
            $return,
            $request->user('employee'),
            Treasury::findOrFail($data['treasury_id']),
            RefundMethod::from($data['method']),
            $data['reference_number'],
        );

        return redirect()->route('admin.returns.index')->with('success', __('Refund recorded.'));
    }
}
