<?php

namespace App\Http\Controllers\Admin\Returns;

use App\Actions\Returns\AcceptReturnShippingFeeAction;
use App\Actions\Returns\ApproveReturnAction;
use App\Actions\Returns\ReceiveReturnAction;
use App\Actions\Returns\RefundReturnAction;
use App\Actions\Returns\RequestReturnAction;
use App\Enums\OrderStatus;
use App\Enums\RefundMethod;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnReason;
use App\Models\Treasury;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/returns (Warehouse Manager + Accounting, both hold
 * returns.manage) — the post-delivery customer-initiated workflow
 * (Section 12): Requested → Approved → Received → Inspected → Refunded.
 * An at-delivery refusal is Accounting's confirmReturnedAtDelivery()
 * instead, already on the Accounting screen (Phase 4). No storefront
 * exists yet for a customer to file one themselves, so "create" here is
 * staff filing a return on the customer's behalf.
 */
class ReturnController extends Controller
{
    public function index(Request $request): Response
    {
        $returns = OrderReturn::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->with(['order:id,order_number,total', 'customer:id,name,phone'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Returns/Index', ['returns' => $returns, 'status' => $request->query('status')]);
    }

    public function create(Request $request): Response
    {
        $order = null;
        if ($request->filled('order_number')) {
            $order = Order::query()
                ->where('order_number', $request->query('order_number'))
                ->where('status', OrderStatus::Delivered)
                ->with('items.productVariant.product')
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

        $order = Order::findOrFail($data['order_id']);

        $return = $action->execute(
            $order,
            $order->customer,
            $data['items'],
            $data['primary_reason_id'],
            $data['customer_notes'] ?? null,
        );

        return redirect()->route('admin.returns.show', $return)->with('success', 'Return request recorded.');
    }

    public function show(OrderReturn $return): Response
    {
        $return->load(['order.customer', 'items.orderItem.productVariant.product', 'reason', 'refund']);

        return Inertia::render('Returns/Show', [
            'return' => $return,
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
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

        return back()->with('success', 'Return shipping fee recorded as accepted.');
    }

    public function approve(Request $request, OrderReturn $return, ApproveReturnAction $action): RedirectResponse
    {
        $action->execute($return, $request->user('employee'));

        return back()->with('success', 'Return approved.');
    }

    public function receive(Request $request, OrderReturn $return, ReceiveReturnAction $action): RedirectResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'exists:warehouses,id']]);

        $action->execute($return, $request->user('employee'), Warehouse::findOrFail($data['warehouse_id']));

        return back()->with('success', 'Return received and restocked.');
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

        return redirect()->route('admin.returns.index')->with('success', 'Refund recorded.');
    }
}
