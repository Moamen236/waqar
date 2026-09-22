<?php

namespace Database\Seeders;

use App\Actions\Checkout\CreateOrderAction;
use App\Actions\Orders\AssignDeliveryAction;
use App\Actions\Orders\CancelOrderAction;
use App\Actions\Orders\ConfirmDeliveryResultAction;
use App\Actions\Orders\ConfirmHandoverAction;
use App\Actions\Orders\ConfirmOrderAction;
use App\Actions\Orders\MarkOrderBackorderAction;
use App\Actions\Orders\PostponeOrderAction;
use App\Enums\CollectedMethod;
use App\Enums\DeliveryAssignmentType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DeliveryRepresentative;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingCompany;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Notifications\Orders\OrderPlacedNotification;
use App\Notifications\Orders\OrderStatusUpdatedNotification;
use Database\Seeders\Concerns\SeedsNotifications;
use Illuminate\Database\Seeder;

/**
 * Drives real orders through every OrderStatus (Section 03's status
 * table) using the actual Actions rather than hand-built rows, so stock
 * reservation/deduction, treasury postings and status history all end up
 * exactly as the real flows would leave them. Complements
 * StorefrontDemoSeeder (New, Out for Delivery, Delivered) by covering the
 * internal-ops-heavy statuses: Checking, Postponed, Backorder, Cancelled,
 * Assigned, Returned (at delivery) and Partially Returned.
 */
class OrderOperationsDemoSeeder extends Seeder
{
    use SeedsNotifications;

    public function run(): void
    {
        $warehouse = Warehouse::where('name', 'Main Warehouse')->firstOrFail();
        $area = Area::query()->with('city.governorate')->first();
        if ($area === null || $area->city === null || $area->city->governorate === null) {
            return;
        }

        $checking = Employee::where('email', 'checking@waqar.test')->first();
        $deliveryManager = Employee::where('email', 'delivery@waqar.test')->first();
        $accountant = Employee::where('email', 'accounting@waqar.test')->first();
        $csAgent = Employee::where('email', 'cs.agent@waqar.test')->first();

        if ($checking === null || $deliveryManager === null || $accountant === null || $csAgent === null) {
            return;
        }

        $rep = DeliveryRepresentative::first();
        $shippingCompany = ShippingCompany::first();
        $treasury = Treasury::where('name', 'Main Cash Register')->first();

        $customer = Customer::updateOrCreate(
            ['email' => 'omar@waqar.test'],
            ['name' => 'Omar Tarek', 'phone' => '+201100000010', 'password' => 'password', 'is_active' => true, 'is_guest' => false],
        );

        $guestCustomer = Customer::updateOrCreate(
            ['email' => 'guest-order-3001@waqar.test'],
            ['name' => 'Heba Youssef', 'phone' => '+201100000011', 'password' => 'password', 'is_active' => true, 'is_guest' => true],
        );

        $createOrder = app(CreateOrderAction::class);

        // Checking — currently under review, no decision made yet.
        $checkingOrder = $this->place($createOrder, $customer, $warehouse, $area, ['CTS-013' => 1]);
        $checkingOrder->update(['status' => OrderStatus::Checking, 'customer_status' => OrderStatus::Checking->customerStatus()]);
        $checkingOrder->statusHistory()->create([
            'from_status' => OrderStatus::New->value,
            'to_status' => OrderStatus::Checking->value,
            'changed_by' => $checking->id,
            'reason' => 'Opened for review',
        ]);

        // Postponed — Checking needs more information before confirming.
        $postponedOrder = $this->place($createOrder, $customer, $warehouse, $area, ['RAG-002' => 1]);
        (new PostponeOrderAction)->execute($postponedOrder, $checking, 'Could not reach customer to confirm delivery address.');

        // Cancelled — Checking rejects it outright.
        $cancelledOrder = $this->place($createOrder, $customer, $warehouse, $area, ['MSH-001' => 1]);
        app(CancelOrderAction::class)->execute($cancelledOrder, $checking, 'Duplicate order placed by mistake.');

        // Confirmed — left sitting in the Delivery Manager's queue, not yet
        // assigned to anyone.
        $confirmedOrder = $this->place($createOrder, $customer, $warehouse, $area, ['KIM-004' => 1]);
        (new ConfirmOrderAction)->execute($confirmedOrder, $checking);

        // Confirmed, then Backorder — an Advertisement (pre-order) item that
        // isn't fulfillable yet (Question 14).
        $backorderOrder = $this->place($createOrder, $customer, $warehouse, $area, ['PRJ-014' => 1]);
        (new ConfirmOrderAction)->execute($backorderOrder, $checking);
        (new MarkOrderBackorderAction)->execute($backorderOrder, $deliveryManager, 'Pre-order stock not yet received from supplier.');

        // Confirmed → Assigned to a delivery representative, and left there
        // (order still in the courier's hands).
        if ($rep !== null) {
            $assignedOrder = $this->place($createOrder, $customer, $warehouse, $area, ['WLT-012' => 1]);
            (new ConfirmOrderAction)->execute($assignedOrder, $checking);
            app(AssignDeliveryAction::class)->execute($assignedOrder, $deliveryManager, DeliveryAssignmentType::Representative, $rep);
        }

        if ($treasury !== null) {
            // Confirmed → Assigned (shipping company) → Out for Delivery →
            // Delivered, with Accounting collecting cash and the treasury
            // balance moving accordingly.
            if ($shippingCompany !== null) {
                $deliveredOrder = $this->place($createOrder, $customer, $warehouse, $area, ['MDS-009' => 1, 'CTS-013' => 1]);
                (new ConfirmOrderAction)->execute($deliveredOrder, $checking);
                app(AssignDeliveryAction::class)->execute($deliveredOrder, $deliveryManager, DeliveryAssignmentType::ShippingCompany, $shippingCompany);
                $this->markOutForDelivery($deliveredOrder, $deliveryManager);
                app(ConfirmDeliveryResultAction::class)->confirmDelivered($deliveredOrder, $accountant, $treasury, CollectedMethod::Cash);

                $this->notify($customer, new OrderPlacedNotification($deliveredOrder->fresh()));
                $this->notify($customer, new OrderStatusUpdatedNotification($deliveredOrder->fresh(), OrderStatus::Delivered->customerStatus()));
            }

            // Confirmed → Assigned → Out for Delivery → refused at the door
            // (Returned) — reservation released, nothing ever left stock.
            if ($rep !== null) {
                $returnedOrder = $this->place($createOrder, $customer, $warehouse, $area, ['BLU-003' => 1]);
                (new ConfirmOrderAction)->execute($returnedOrder, $checking);
                app(AssignDeliveryAction::class)->execute($returnedOrder, $deliveryManager, DeliveryAssignmentType::Representative, $rep);
                $this->markOutForDelivery($returnedOrder, $deliveryManager);
                app(ConfirmDeliveryResultAction::class)->confirmReturnedAtDelivery($returnedOrder, $accountant);

                $this->notify($customer, new OrderStatusUpdatedNotification($returnedOrder->fresh(), OrderStatus::Returned->customerStatus()));
            }

            // Confirmed → Assigned → Out for Delivery → customer keeps one
            // line and refuses the other (Partially Returned).
            if ($rep !== null) {
                $partialOrder = $this->place($createOrder, $customer, $warehouse, $area, ['FLT-006' => 1, 'OSJ-010' => 1]);
                (new ConfirmOrderAction)->execute($partialOrder, $checking);
                app(AssignDeliveryAction::class)->execute($partialOrder, $deliveryManager, DeliveryAssignmentType::Representative, $rep);
                $this->markOutForDelivery($partialOrder, $deliveryManager);

                $keptItem = $partialOrder->items()->first();
                app(ConfirmDeliveryResultAction::class)->confirmPartiallyReturned(
                    $partialOrder,
                    $accountant,
                    $treasury,
                    CollectedMethod::Cash,
                    (float) $keptItem->subtotal,
                    [$keptItem->id => $keptItem->quantity],
                );

                $this->notify($customer, new OrderStatusUpdatedNotification($partialOrder->fresh(), OrderStatus::PartiallyReturned->customerStatus()));
            }
        }

        // Customer Service phone order (Section 03, Flow 2) — placed by an
        // employee on a guest customer's behalf.
        $createOrder->execute(
            $guestCustomer,
            [['product_variant_id' => $this->variantFor('SSD-008')->id, 'quantity' => 1]],
            $warehouse,
            $area->city->governorate->id,
            $area->city->id,
            $area->district_id,
            $area->id,
            '18 Corniche El Nil, Maadi',
            $guestCustomer->name,
            $guestCustomer->phone,
            OrderSource::CustomerService,
            $csAgent,
        );
    }

    /**
     * @param  array<string, int>  $skuQuantities
     */
    private function place(CreateOrderAction $action, Customer $customer, Warehouse $warehouse, Area $area, array $skuQuantities): Order
    {
        $items = [];
        foreach ($skuQuantities as $sku => $quantity) {
            $variant = $this->variantFor($sku);
            if ($variant !== null) {
                $items[] = ['product_variant_id' => $variant->id, 'quantity' => $quantity];
            }
        }

        return $action->execute(
            $customer,
            $items,
            $warehouse,
            $area->city->governorate->id,
            $area->city->id,
            $area->district_id,
            $area->id,
            '12 El Merghany Street, Heliopolis',
            $customer->name,
            $customer->phone,
        );
    }

    private function variantFor(string $sku): ?ProductVariant
    {
        return Product::where('sku', $sku)->first()?->variants()->first();
    }

    /**
     * The Assigned → Out for Delivery hop, through the same Action the
     * Accounting screen uses. This used to be written inline here because
     * no Action modelled it; ConfirmHandoverAction now does, and going
     * through it keeps the seeded data reachable by the same guard and
     * lock real traffic passes.
     */
    private function markOutForDelivery(Order $order, Employee $employee): void
    {
        app(ConfirmHandoverAction::class)->execute($order, $employee, 'Handed to courier');
    }
}
