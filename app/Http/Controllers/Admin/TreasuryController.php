<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Http\Controllers\Controller;
use App\Models\Treasury;
use App\Services\Treasury\TreasuryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/treasury (Section 20 #23, "a real Treasury ledger UI — none
 * exist anywhere in the admin template") — every treasury account and
 * its transaction ledger, plus manual transaction entry and
 * inter-treasury transfers. TreasuryService remains the only place a
 * balance actually changes; this controller only calls it.
 */
class TreasuryController extends Controller implements HasMiddleware
{
    /**
     * Not CRUD-shaped — the three writes are opening a new account,
     * posting a manual transaction, and transferring between two existing
     * accounts, none of which is really an "update" of an existing row —
     * so split by what each one does.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('permission:treasury.view', only: ['index']),
            new Middleware('permission:treasury.create', only: ['store']),
            new Middleware('permission:treasury.transactions.create', only: ['storeTransaction']),
            new Middleware('permission:treasury.transfer', only: ['transfer']),
        ];
    }

    public function index(Request $request): Response
    {
        $treasuries = Treasury::query()->orderBy('name')->get();

        $selected = $request->filled('treasury_id')
            ? Treasury::findOrFail($request->integer('treasury_id'))
            : $treasuries->first();

        return Inertia::render('Treasury/Index', [
            'treasuries' => $treasuries,
            'selected' => $selected,
            'transactions' => $selected
                ? $selected->transactions()->with('createdBy:id,full_name')->latest('id')->paginate(30)->withQueryString()
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(TreasuryType::class)],
            'account_number' => ['nullable', 'string', 'max:255'],
            'current_balance' => ['required', 'numeric'],
        ]);

        Treasury::create($data);

        return back()->with('success', __('Treasury account created.'));
    }

    public function storeTransaction(Request $request, TreasuryService $treasuryService): RedirectResponse
    {
        $data = $request->validate([
            'treasury_id' => ['required', 'exists:treasuries,id'],
            'type' => ['required', Rule::enum(TreasuryTransactionType::class)],
            'amount' => ['required', 'numeric'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $treasuryService->recordTransaction(
            Treasury::findOrFail($data['treasury_id']),
            TreasuryTransactionType::from($data['type']),
            (float) $data['amount'],
            $request->user('employee'),
            null,
            $data['description'] ?? null,
        );

        return back()->with('success', __('Transaction recorded.'));
    }

    public function transfer(Request $request, TreasuryService $treasuryService): RedirectResponse
    {
        $data = $request->validate([
            'from_treasury_id' => ['required', 'exists:treasuries,id', 'different:to_treasury_id'],
            'to_treasury_id' => ['required', 'exists:treasuries,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $treasuryService->transfer(
            Treasury::findOrFail($data['from_treasury_id']),
            Treasury::findOrFail($data['to_treasury_id']),
            (float) $data['amount'],
            $request->user('employee'),
            $data['notes'] ?? null,
        );

        return back()->with('success', __('Transfer recorded.'));
    }
}
