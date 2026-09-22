<?php

namespace App\Reports\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DeliveryAssignment;
use App\Models\Employee;
use App\Reports\Concerns\AppliesStandardFilters;
use App\Reports\ReportColumn;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * ORD-05 · Delivery Performance by Carrier — representatives and shipping
 * companies, side by side.
 *
 * Rooted in `delivery_assignments` rather than `orders`, because the
 * denominator is *assignments made*, not orders that happen to name a
 * carrier: an order reassigned from one company to another was handed to
 * both, and each should answer for its own attempt.
 *
 * Money follows the post-Phase C rule — `payments.collected_amount` is
 * already net of the shipping the courier kept at the door, so it is
 * summed as-is and never compared against the gross `payments.amount`.
 */
class DeliveryPerformanceReport extends ReportDefinition
{
    use AppliesStandardFilters;

    public function key(): string
    {
        return 'orders.delivery-performance';
    }

    public function group(): string
    {
        return 'orders';
    }

    public function title(): string
    {
        return 'reports.orders.delivery.title';
    }

    public function description(): string
    {
        return 'reports.orders.delivery.description';
    }

    public function permission(): string
    {
        return 'reports.orders.view';
    }

    public function isPaginated(): bool
    {
        return false;
    }

    public function supportsComparison(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function filters(): array
    {
        return [
            'preset', 'date_from', 'date_to', 'compare_to',
            'assignment_type', 'representative_id', 'shipping_company_id',
            'order_source', 'governorate_id', 'city_id', 'district_id', 'area_id',
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(): array
    {
        return [
            ReportColumn::text('carrier', 'reports.columns.carrier'),
            ReportColumn::enum('assignment_type', 'reports.columns.carrier_type', 'reports.carrierType.'),
            ReportColumn::number('assigned', 'reports.columns.assigned'),
            ReportColumn::number('delivered', 'reports.columns.delivered'),
            ReportColumn::number('returned', 'reports.columns.returned'),
            ReportColumn::percent('success_rate', 'reports.columns.success_rate'),
            ReportColumn::money('collected', 'reports.columns.collected'),
            ReportColumn::money('courier_fees', 'reports.columns.courier_fees'),
        ];
    }

    public function defaultSort(): ?array
    {
        return ['assigned', 'desc'];
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return ['reports.notes.net_of_shipping'];
    }

    public function query(Employee $employee, array $filters): Builder
    {
        $period = $this->resolvePeriod($filters);
        $delivered = OrderStatus::Delivered->value;
        $partial = OrderStatus::PartiallyReturned->value;
        $returned = OrderStatus::Returned->value;
        $collected = PaymentStatus::Collected->value;
        $partiallyCollected = PaymentStatus::PartiallyCollected->value;

        return DeliveryAssignment::query()
            ->join('orders', 'orders.id', '=', 'delivery_assignments.order_id')
            ->leftJoin('delivery_representatives', 'delivery_representatives.id', '=', 'delivery_assignments.delivery_representative_id')
            ->leftJoin('shipping_companies', 'shipping_companies.id', '=', 'delivery_assignments.shipping_company_id')
            ->whereIn('orders.id', $this->visibleOrders($employee))
            ->where('delivery_assignments.assigned_at', '>=', $period['from'])
            ->where('delivery_assignments.assigned_at', '<', $period['to'])
            ->when(! empty($filters['assignment_type']), fn ($q) => $q->where('delivery_assignments.assignment_type', (string) $filters['assignment_type']))
            ->when($this->listOf($filters, 'representative_id'), fn ($q, array $v) => $q->whereIn('delivery_assignments.delivery_representative_id', $v))
            ->when($this->listOf($filters, 'shipping_company_id'), fn ($q, array $v) => $q->whereIn('delivery_assignments.shipping_company_id', $v))
            ->when(! empty($filters['order_source']), fn ($q) => $q->where('orders.order_source', (string) $filters['order_source']))
            ->when(! empty($filters['governorate_id']), fn ($q) => $q->where('orders.shipping_governorate_id', (int) $filters['governorate_id']))
            ->when(! empty($filters['city_id']), fn ($q) => $q->where('orders.shipping_city_id', (int) $filters['city_id']))
            ->when(! empty($filters['district_id']), fn ($q) => $q->where('orders.shipping_district_id', (int) $filters['district_id']))
            ->when(! empty($filters['area_id']), fn ($q) => $q->where('orders.shipping_area_id', (int) $filters['area_id']))
            ->selectRaw('COALESCE(delivery_representatives.name, shipping_companies.name) as carrier')
            ->selectRaw('delivery_assignments.assignment_type as assignment_type')
            ->selectRaw('COUNT(*) as assigned')
            ->selectRaw("SUM(CASE WHEN orders.status IN ('{$delivered}', '{$partial}') THEN 1 ELSE 0 END) as delivered")
            ->selectRaw("SUM(CASE WHEN orders.status = '{$returned}' THEN 1 ELSE 0 END) as returned")
            // Collected cash is read from the payment, not the order total:
            // a partially returned order banks less than it was worth.
            ->selectRaw("COALESCE(SUM((SELECT COALESCE(SUM(p.collected_amount), 0) FROM payments p WHERE p.order_id = orders.id AND p.status IN ('{$collected}', '{$partiallyCollected}'))), 0) as collected")
            ->selectRaw("COALESCE(SUM(CASE WHEN orders.status IN ('{$delivered}', '{$partial}') THEN orders.shipping_amount ELSE 0 END), 0) as courier_fees")
            ->groupBy('carrier', 'delivery_assignments.assignment_type')
            ->orderByDesc('assigned');
    }

    /**
     * @return array<string, mixed>
     */
    public function map(object $row): array
    {
        $assigned = (int) $row->assigned;

        return [
            'carrier' => $row->carrier,
            'assignment_type' => $row->assignment_type instanceof \BackedEnum
                ? $row->assignment_type->value
                : $row->assignment_type,
            'assigned' => $assigned,
            'delivered' => (int) $row->delivered,
            'returned' => (int) $row->returned,
            'success_rate' => $this->ratio((float) $row->delivered, (float) $assigned),
            'collected' => (float) $row->collected,
            'courier_fees' => (float) $row->courier_fees,
        ];
    }
}
