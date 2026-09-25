<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/warehouses — the stock locations inventory is held against,
 * plain CRUD split by warehouses.view/create/update/delete.
 */
class WarehouseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:warehouses.view', only: ['index']),
            new Middleware('permission:warehouses.create', only: ['create', 'store']),
            new Middleware('permission:warehouses.update', only: ['edit', 'update']),
            new Middleware('permission:warehouses.delete', only: ['destroy']),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Warehouses/Index', [
            'warehouses' => Warehouse::query()
                ->with('manager:id,full_name')
                ->withSum('inventory as stock_quantity', 'quantity')
                ->orderBy('id')
                ->paginate(20),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Warehouses/Form', ['warehouse' => null, 'managers' => $this->managers()]);
    }

    public function store(Request $request): RedirectResponse
    {
        Warehouse::create($this->validated($request));

        return redirect()->route('admin.warehouses.index')->with('success', __('Warehouse created.'));
    }

    public function edit(Warehouse $warehouse): Response
    {
        return Inertia::render('Warehouses/Form', ['warehouse' => $warehouse, 'managers' => $this->managers()]);
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $warehouse->update($this->validated($request));

        return redirect()->route('admin.warehouses.index')->with('success', __('Warehouse updated.'));
    }

    /**
     * Refused once the warehouse has any stock history: warehouse_inventory
     * cascades on delete, so removing one that still holds stock would wipe
     * it silently, and movements/transfers would lose the location they
     * point at. Deactivate it instead.
     */
    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $inUse = $warehouse->inventory()->where(fn ($q) => $q->where('quantity', '>', 0)->orWhere('reserved_quantity', '>', 0))->exists()
            || InventoryMovement::query()->where('warehouse_id', $warehouse->id)->exists()
            || StockTransfer::query()->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id)->exists();

        if ($inUse) {
            return back()->with('error', __('This warehouse has stock history and cannot be deleted — deactivate it instead.'));
        }

        $warehouse->delete();

        return redirect()->route('admin.warehouses.index')->with('success', __('Warehouse deleted.'));
    }

    /**
     * @return Collection<int, Employee>
     */
    private function managers(): Collection
    {
        return Employee::query()->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'manager_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
