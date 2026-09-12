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
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/delivery — the assignment board (Section 14): Confirmed orders
 * ready to hand off to a representative or shipping company, plus what's
 * already out.
 */
class DeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        $ready = Order::query()
            ->visibleTo($request->user('employee'))
            ->where('status', OrderStatus::Confirmed)
            ->with('customer')
            ->latest('id')
            ->paginate(20, ['*'], 'ready')
            ->withQueryString();

        $active = Order::query()
            ->visibleTo($request->user('employee'))
            ->whereIn('status', [OrderStatus::Assigned, OrderStatus::OutForDelivery])
            ->with(['customer', 'deliveryRepresentative', 'shippingCompany'])
            ->latest('id')
            ->paginate(20, ['*'], 'active')
            ->withQueryString();

        return Inertia::render('Delivery/Index', [
            'ready' => $ready,
            'active' => $active,
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

        $type = DeliveryAssignmentType::from($data['assignment_type']);
        $assignee = $type === DeliveryAssignmentType::Representative
            ? DeliveryRepresentative::findOrFail($data['assignee_id'])
            : ShippingCompany::findOrFail($data['assignee_id']);

        $action->execute($order, $request->user('employee'), $type, $assignee);

        return back()->with('success', "Order #{$order->order_number} assigned.");
    }
}
