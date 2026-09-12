<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Models\ShippingCompany;
use App\Models\ShippingCompanyStatement;
use App\Models\Treasury;
use App\Services\Treasury\ReconciliationService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/accounting/reconciliation (Section 14) — shipping-company
 * statements: what a company delivered/returned in a period, what it
 * owes in fees, and what's actually been transferred back.
 */
class ReconciliationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/Reconciliation/Index', [
            'shippingCompanies' => ShippingCompany::query()
                ->withCount(['statements as open_statements_count' => fn ($q) => $q->where('status', 'open')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(ShippingCompany $shippingCompany, Request $request, ReconciliationService $reconciliation): Response
    {
        $draft = null;
        if ($request->filled(['period_start', 'period_end'])) {
            $draft = $reconciliation->buildDraft(
                $shippingCompany,
                Carbon::parse($request->query('period_start'))->startOfDay(),
                Carbon::parse($request->query('period_end'))->endOfDay(),
            );
        }

        return Inertia::render('Accounting/Reconciliation/Show', [
            'shippingCompany' => $shippingCompany,
            'statements' => $shippingCompany->statements()->latest('id')->get(),
            'draft' => $draft,
            'periodStart' => $request->query('period_start'),
            'periodEnd' => $request->query('period_end'),
            'treasuries' => Treasury::query()->where('is_active', true)->get(['id', 'name', 'type']),
        ]);
    }

    public function store(Request $request, ShippingCompany $shippingCompany, ReconciliationService $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $draft = $reconciliation->buildDraft(
            $shippingCompany,
            Carbon::parse($data['period_start'])->startOfDay(),
            Carbon::parse($data['period_end'])->endOfDay(),
        );

        $shippingCompany->statements()->create([
            ...$draft,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'created_by' => $request->user('employee')->id,
        ]);

        return redirect()
            ->route('admin.accounting.reconciliation.show', $shippingCompany)
            ->with('success', 'Statement created.');
    }

    public function recordTransfer(Request $request, ShippingCompanyStatement $statement, ReconciliationService $reconciliation): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $reconciliation->recordTransfer(
            $statement,
            (float) $data['amount'],
            Treasury::findOrFail($data['treasury_id']),
            $request->user('employee'),
        );

        return redirect()
            ->route('admin.accounting.reconciliation.show', $statement->shipping_company_id)
            ->with('success', 'Transfer recorded.');
    }
}
