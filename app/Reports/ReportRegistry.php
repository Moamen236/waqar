<?php

namespace App\Reports;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * The catalogue of reports, and the only place a report key becomes a
 * class.
 *
 * One registry rather than one route per report: thirty-nine routes would
 * mean thirty-nine middleware declarations to keep in step with
 * thirty-nine permissions, and the sidebar would grow by one line every
 * time someone adds a breakdown. Four routes read this list instead.
 *
 * Order within a group is the order the catalogue renders — the most-used
 * report first, not alphabetical.
 */
class ReportRegistry
{
    /**
     * Group keys in navigation order. Kept explicit so a new report cannot
     * silently invent a group the menu has no entry for.
     *
     * @var list<string>
     */
    public const GROUPS = [
        'orders',
        'sales',
        'inventory',
        'returns',
        'finance',
        'employees',
        'audit',
        'executive',
    ];

    /**
     * @var array<string, ReportDefinition>|null
     */
    private ?array $reports = null;

    /**
     * @var list<class-string<ReportDefinition>>
     */
    private const DEFINITIONS = [
        Orders\OrdersSummaryReport::class,
        Orders\StatusPipelineReport::class,
        Orders\LifecycleSlaReport::class,
        Orders\CancellationAnalysisReport::class,
        Orders\DeliveryPerformanceReport::class,
        Orders\SourceComparisonReport::class,
        Sales\SalesSummaryReport::class,
        Sales\SalesByProductReport::class,
        Sales\SalesByCategoryReport::class,
        Sales\SalesByGeographyReport::class,
        Sales\DiscountEffectivenessReport::class,
        Sales\GrossMarginReport::class,
        Sales\CustomerSalesReport::class,
        Inventory\StockOnHandReport::class,
        Inventory\LowStockReport::class,
        Inventory\MovementLedgerReport::class,
        Inventory\StockTurnoverReport::class,
        Inventory\ShrinkageReport::class,
        Inventory\ReservationIntegrityReport::class,
        Returns\ReturnsSummaryReport::class,
        Returns\ReturnReasonsReport::class,
        Returns\ProductReturnRateReport::class,
        Returns\RefundRegisterReport::class,
        Returns\ReturnCycleTimeReport::class,
        Finance\TreasuryBalancesReport::class,
        Finance\CashCollectionReport::class,
        Finance\OutstandingReceivablesReport::class,
        Finance\ReconciliationReport::class,
        Finance\ExpensesReport::class,
        Finance\TreasuryTransfersReport::class,
        Finance\ProfitAndLossReport::class,
        Employees\CustomerServiceScorecardReport::class,
        Employees\CheckingPerformanceReport::class,
        Employees\DeliveryAssignmentActivityReport::class,
        Employees\AccountingActivityReport::class,
        Employees\WarehouseActivityReport::class,
        Audit\AuditTrailReport::class,
        Audit\AccessChangesReport::class,
        Audit\EntityHistoryReport::class,
    ];

    /**
     * @return array<string, ReportDefinition>
     */
    public function all(): array
    {
        if ($this->reports === null) {
            $this->reports = [];

            foreach (self::DEFINITIONS as $class) {
                $report = app($class);
                $this->reports[$report->key()] = $report;
            }
        }

        return $this->reports;
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The reports this employee may open, grouped for the catalogue.
     *
     * Permission is checked here rather than in the view so a report the
     * viewer cannot open is absent from the payload entirely — the same
     * approach `DashboardController` takes with its tiles, and the reason
     * a Checking employee's catalogue simply has no Finance section rather
     * than a section full of locked cards.
     *
     * @return Collection<string, Collection<int, ReportDefinition>>
     */
    public function visibleTo(Employee $employee): Collection
    {
        return collect($this->all())
            ->filter(fn (ReportDefinition $report) => $employee->can($report->permission()))
            ->groupBy(fn (ReportDefinition $report) => $report->group())
            ->sortBy(fn ($reports, string $group) => array_search($group, self::GROUPS, true));
    }
}
