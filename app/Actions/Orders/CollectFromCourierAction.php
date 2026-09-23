<?php

namespace App\Actions\Orders;

use App\Enums\CollectedMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Treasury;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One courier hands over one sum of cash for several orders, and it is
 * less than they owe. Accounting enters the one figure; this splits it.
 *
 * The split is oldest order first: each order is paid in full before the
 * next one gets anything, so a shortfall lands on as few orders as
 * possible and each of them is either settled or clearly still owing —
 * never every order a little bit short. (Three orders owing 1600, 1800
 * and 600 with 1800 handed over: the first is settled, the second gets
 * the remaining 200 and still owes 1600, the third gets nothing and
 * still owes 600.)
 *
 * Collecting is not delivering. An order still in the building is
 * handed over (Assigned → Out for Delivery) — the courier pays when they
 * take the goods — and its share is banked as a prepayment; an order
 * already out stays out. The delivery result is confirmed later, on its
 * own, and ConfirmDeliveryResultAction then asks only for what the
 * prepayment didn't cover (or hands it back if the customer refused).
 * No stock moves here: it deducts on Delivered and nowhere else.
 *
 * Each order's money goes through collectBalance(), the same Action a
 * single instalment would, so treasury and payment rows look exactly as
 * they do one at a time.
 *
 * All or nothing, unlike AccountingController::settleBulk(). There each
 * order is settled for its own full amount, so skipping one that moved
 * on harms nothing. Here the orders share one sum: skipping one would
 * send its share on to the next order and bank a split nobody saw on
 * the screen.
 */
class CollectFromCourierAction
{
    public function __construct(
        private readonly ConfirmDeliveryResultAction $delivery,
        private readonly ConfirmHandoverAction $handover,
    ) {}

    /**
     * @param  array<int, int|string>  $orderIds
     * @return list<array{id: int, order_number: int, owed: float, applied: float, balance: float}>
     *
     * @throws RuntimeException when the selection or the amount can't be split
     */
    public function execute(
        array $orderIds,
        Employee $accountant,
        Treasury $treasury,
        CollectedMethod $collectedMethod,
        float $amount,
    ): array {
        return DB::transaction(function () use ($orderIds, $accountant, $treasury, $collectedMethod, $amount) {
            // visibleTo, not a bare whereIn: a data-scoped role must not
            // settle an order it cannot see by posting its id.
            $orders = Order::query()
                ->visibleTo($accountant)
                ->whereIn('id', $orderIds)
                ->with('payments')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orders->count() !== count(array_unique(array_map('intval', $orderIds)))) {
                throw new RuntimeException(__('Some of the selected orders are no longer available.'));
            }

            $this->assertOneCourier($orders->all());

            foreach ($orders as $order) {
                if (! $this->isAwaitingResult($order) && $order->payment_status !== PaymentStatus::PartiallyCollected) {
                    throw new RuntimeException(__('Order #:number has nothing left to collect.', ['number' => $order->order_number]));
                }
            }

            $owed = $orders->mapWithKeys(fn (Order $order) => [$order->id => $order->stillOwed()]);
            $totalOwed = round($owed->sum(), 2);

            if ($amount > $totalOwed) {
                throw new RuntimeException(__('The courier only owes :amount for these orders.', [
                    'amount' => number_format($totalOwed, 2),
                ]));
            }

            $remaining = round($amount, 2);
            $lines = [];

            foreach ($orders as $order) {
                $share = round(min($remaining, $owed[$order->id]), 2);
                $remaining = round($remaining - $share, 2);

                // Handed over even on a zero share: the courier took the
                // goods, they just haven't paid for these ones yet.
                if ($order->status === OrderStatus::Assigned) {
                    $this->handover->execute($order, $accountant);
                }

                if ($share > 0) {
                    $this->delivery->collectBalance($order, $accountant, $treasury, $collectedMethod, $share);
                }

                $lines[] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'owed' => $owed[$order->id],
                    'applied' => $share,
                    'balance' => round($owed[$order->id] - $share, 2),
                ];
            }

            return $lines;
        });
    }

    /**
     * Not yet delivered: still in the building (handed over here) or
     * already out with the courier (prepaid here).
     */
    private function isAwaitingResult(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Assigned, OrderStatus::OutForDelivery], true);
    }

    /**
     * One sum of cash comes from one courier. Mixing couriers would bank
     * one person's money against another's orders.
     *
     * @param  list<Order>  $orders
     */
    private function assertOneCourier(array $orders): void
    {
        $couriers = array_unique(array_map(
            fn (Order $order) => $order->delivery_representative_id !== null
                ? 'representative:'.$order->delivery_representative_id
                : ($order->shipping_company_id !== null ? 'company:'.$order->shipping_company_id : 'none'),
            $orders,
        ));

        if (count($couriers) !== 1 || $couriers[array_key_first($couriers)] === 'none') {
            throw new RuntimeException(__('Select orders carried by the same courier.'));
        }
    }
}
