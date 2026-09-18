<?php

namespace App\Policies;

use App\Enums\OrderSource;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Order;

/**
 * Storefront side: a customer only ever sees their own orders — this is
 * the "cross-customer order access" critical test (Section 23). Staff
 * side reuses Order::scopeVisibleTo() at the query level (Team Leader
 * team-scoping, Question 16/18) rather than duplicating that check here;
 * this policy's `viewAsEmployee` just gates on the permission itself.
 */
class OrderPolicy
{
    public function view(Customer $customer, Order $order): bool
    {
        return $order->customer_id === $customer->id;
    }

    public function viewAsEmployee(Employee $employee, Order $order): bool
    {
        if (! $employee->can('orders.view')) {
            return false;
        }

        if ($employee->hasRole('Customer Service Team Leader')) {
            $teamIds = $employee->teamMembers()->pluck('id')->push($employee->id);

            return $order->created_by_employee_id !== null
                && $teamIds->contains($order->created_by_employee_id);
        }

        // A plain agent only reaches the orders they placed themselves —
        // the row-level half of Order::scopeVisibleTo()'s agent tier.
        if ($employee->hasRole('Customer Service')) {
            return $order->created_by_employee_id === $employee->id;
        }

        // Store Orders: storefront orders only, same as its query tier.
        if ($employee->hasRole('Store Orders')) {
            return $order->order_source === OrderSource::Website;
        }

        return true;
    }
}
