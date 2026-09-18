<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * /admin/employees — staff management, including the
 * team_leader_id hierarchy (Section 15, Question 16): a Customer Service
 * agent optionally reports to another employee holding the Customer
 * Service Team Leader role.
 */
class EmployeeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:employees.view', only: ['index']),
            new Middleware('permission:employees.create', only: ['create', 'store']),
            new Middleware('permission:employees.update', only: ['edit', 'update']),
            new Middleware('permission:employees.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $employees = Employee::query()
            ->visibleTo($request->user('employee'))
            ->with('roles:id,name')
            ->latest('id')
            ->paginate(20);

        return Inertia::render('Employees/Index', ['employees' => $employees]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Employees/Form', [
            'employee' => null,
            'roles' => $this->assignableRoles($request->user('employee')),
            'teamLeaders' => Employee::query()->role('Customer Service Team Leader')->get(['id', 'full_name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, actor: $request->user('employee'));
        $role = $data['role'];
        unset($data['role']);

        $employee = Employee::create($data);
        $employee->syncRoles([$role]);

        return redirect()->route('admin.employees.index')->with('success', __('Employee created.'));
    }

    public function edit(Request $request, Employee $employee): Response
    {
        $employee->load('roles:id,name');

        return Inertia::render('Employees/Form', [
            'employee' => $employee,
            'roles' => $this->assignableRoles($request->user('employee')),
            'teamLeaders' => Employee::query()->role('Customer Service Team Leader')->where('id', '!=', $employee->id)->get(['id', 'full_name']),
        ]);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $data = $this->validated($request, $employee, $request->user('employee'));
        $role = $data['role'];
        unset($data['role']);

        if (empty($data['password'])) {
            unset($data['password']);
        }
        if (empty($data['national_id_number'])) {
            unset($data['national_id_number']);
        }

        $employee->update($data);
        $employee->syncRoles([$role]);

        return redirect()->route('admin.employees.index')->with('success', __('Employee updated.'));
    }

    /**
     * Two guards, both mirroring assignableRoles()'s own escalation logic:
     * an employee can't delete their own account (they'd otherwise lock
     * themselves out mid-request), and only a Super Admin can remove
     * another Super Admin.
     */
    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $actor = $request->user('employee');

        if ($employee->id === $actor->id) {
            return back()->with('error', __('You cannot delete your own account.'));
        }

        if ($employee->hasRole('Super Admin') && ! $actor->hasRole('Super Admin')) {
            abort(403);
        }

        $employee->delete();

        return redirect()->route('admin.employees.index')->with('success', __('Employee deleted.'));
    }

    /**
     * Super Admin is deliberately excluded from what any non-Super-Admin
     * employee — even one holding employees.manage — can assign, so
     * granting that one permission to another role later (via the
     * roles/permissions matrix) can never become a privilege-escalation
     * path to Super Admin itself.
     *
     * @return Collection<int, string>
     */
    private function assignableRoles(Employee $actor): Collection
    {
        return Role::query()
            ->where('guard_name', 'employee')
            ->when(! $actor->hasRole('Super Admin'), fn ($query) => $query->where('name', '!=', 'Super Admin'))
            ->orderBy('name')
            ->pluck('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Employee $employee = null, ?Employee $actor = null): array
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('employees', 'email')->ignore($employee?->id)],
            'phone' => ['required', 'string', 'max:30'],
            'password' => [$employee ? 'nullable' : 'required', 'string', 'min:8'],
            'residence_address' => ['required', 'string', 'max:500'],
            // Required on create, optional on update (PII, hidden from
            // the edit form's props, so there's nothing to prefill and
            // re-validate against — leaving it blank keeps the existing
            // value, same pattern as password).
            'national_id_number' => [$employee ? 'nullable' : 'required', 'string', 'max:50'],
            'is_active' => ['required', 'boolean'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'employee')],
            'team_leader_id' => ['nullable', 'exists:employees,id'],
        ]);

        if ($data['role'] === 'Super Admin' && ($actor === null || ! $actor->hasRole('Super Admin'))) {
            abort(403, __('Only a Super Admin can assign the Super Admin role.'));
        }

        return $data;
    }
}
