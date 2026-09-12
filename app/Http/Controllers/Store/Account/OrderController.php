<?php

namespace App\Http\Controllers\Store\Account;

use App\Actions\Orders\CancelOrderAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\OrderTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The account area's Orders tab and order detail. Everything a customer
 * sees here is the customer-facing status (Section 03's mapping table) —
 * the internal Checking/Assigned/Backorder vocabulary never leaves the
 * admin side.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = $request->user('customer');

        return Inertia::render('Account/Orders', [
            'orders' => Order::where('customer_id', $customer->id)
                ->with(['items'])
                ->latest('id')
                ->get()
                ->map(fn (Order $order) => $this->orderCard($order))
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, int $order): Response
    {
        $model = $this->ownedOrder($request, $order);

        return Inertia::render('Account/OrderShow', [
            'order' => [
                'order_number' => $model->order_number,
                'status' => $model->customer_status->value,
                'placed_at' => $model->created_at?->toDateTimeString(),
                'subtotal' => (float) $model->subtotal,
                'discount_amount' => (float) $model->discount_amount,
                'shipping_amount' => (float) $model->shipping_amount,
                'total' => (float) $model->total,
                'cancellable' => $this->cancellable($model),
                'address' => [
                    'recipient_name' => $model->shipping_recipient_name,
                    'phone' => $model->shipping_phone,
                    'line' => $model->shipping_address_line,
                    'area' => $model->shippingArea?->getTranslation('name', app()->getLocale()),
                    'district' => $model->shippingDistrict?->getTranslation('name', app()->getLocale()),
                    'city' => $model->shippingCity?->getTranslation('name', app()->getLocale()),
                    'governorate' => $model->shippingGovernorate?->getTranslation('name', app()->getLocale()),
                ],
                'items' => $model->items->map(fn ($item) => [
                    'name' => $item->product_name_snapshot,
                    'sku' => $item->variant_sku_snapshot,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ]),
            ],
            'timeline' => OrderTimeline::for($model),
        ]);
    }

    /**
     * Section 03's status table lists the customer as a valid trigger for
     * Cancelled while the order is still Pending — i.e. before Checking
     * has confirmed it. Past that point it's the Checking department's
     * call, not the customer's, and the button isn't offered.
     */
    public function cancel(Request $request, int $order, CancelOrderAction $action): RedirectResponse
    {
        $model = $this->ownedOrder($request, $order);

        if (! $this->cancellable($model)) {
            return back()->with('error', __('This order can no longer be cancelled online — please contact customer service.'));
        }

        try {
            $action->execute($model, changedBy: null, reason: 'Cancelled by customer');
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('Your order has been cancelled.'));
    }

    /**
     * Its own method with an explicit return shape rather than a closure
     * nested inside index() — see ProductPresenter::variant() for why.
     *
     * @return array{order_number: int, status: string, total: float, placed_at: string|null, cancellable: bool, items: array<int, array{name: string, sku: string, quantity: int, unit_price: float}>}
     */
    private function orderCard(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'status' => $order->customer_status->value,
            'total' => (float) $order->total,
            'placed_at' => $order->created_at?->toDateString(),
            'cancellable' => $this->cancellable($order),
            'items' => $order->items
                ->map(fn (OrderItem $item) => [
                    'name' => (string) $item->product_name_snapshot,
                    'sku' => (string) $item->variant_sku_snapshot,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])
                ->values()
                ->all(),
        ];
    }

    private function cancellable(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::New, OrderStatus::Checking], true);
    }

    private function ownedOrder(Request $request, int $orderNumber): Order
    {
        return Order::where('order_number', $orderNumber)
            ->where('customer_id', $request->user('customer')->id)
            ->with(['items', 'statusHistory', 'shippingArea', 'shippingDistrict', 'shippingCity', 'shippingGovernorate'])
            ->firstOrFail();
    }
}
