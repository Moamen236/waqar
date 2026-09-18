<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section 17's template audit: customer-add/edit.html turned out to be a
 * mislabeled Seller-list copy, not usable as reference — this is built
 * from scratch. Customer Service uses this both directly (customers.
 * manage) and as the first step before /admin/orders/create when the
 * customer doesn't exist yet.
 */
class CustomerController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:customers.view', only: ['index']),
            new Middleware('permission:customers.create', only: ['create', 'store']),
            new Middleware('permission:customers.update', only: ['edit', 'update']),
            new Middleware('permission:customers.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): Response
    {
        $customers = Customer::query()
            ->when($request->filled('q'), fn ($query) => $query->where(function ($q) use ($request) {
                $term = "%{$request->query('q')}%";
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            }))
            ->withCount('orders')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Customers/Index', ['customers' => $customers, 'q' => $request->query('q')]);
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Form', ['customer' => null, 'geoTree' => GeoTree::tree()]);
    }

    /**
     * A customer added here is one Customer Service is filing on someone's
     * behalf — a phone order, a walk-in — not someone signing themselves
     * up, so there is no email or password to collect. Same is_guest
     * arrangement guest checkout already uses (CheckoutController): a
     * generated, unguessable password and is_guest = true, so the record
     * exists and can hold orders without anyone being able to log into it.
     * If the same person later registers on the storefront with a real
     * email, that's a fresh, separate account — nothing here to claim
     * against without an email on file.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'is_active' => ['required', 'boolean'],
            'address' => ['required', 'array'],
            'address.governorate_id' => ['required', 'exists:governorates,id'],
            'address.city_id' => ['required', 'exists:cities,id'],
            'address.district_id' => ['nullable', 'exists:districts,id'],
            'address.area_id' => ['required', 'exists:areas,id'],
            'address.address_line' => ['required', 'string', 'max:500'],
        ]);

        $customer = Customer::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Str::password(32),
            'is_active' => $data['is_active'],
            'is_guest' => true,
        ]);

        $customer->addresses()->create([
            ...$data['address'],
            'recipient_name' => $data['name'],
            'phone' => $data['phone'],
            'is_default' => true,
        ]);

        return redirect()->route('admin.customers.index')->with('success', __('Customer created.'));
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('Customers/Form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Nullable, not required: a customer added from the dashboard
            // (store() above) has none, and editing their phone number
            // shouldn't be blocked on inventing one.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $customer->update($data);

        return redirect()->route('admin.customers.index')->with('success', __('Customer updated.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()->route('admin.customers.index')->with('success', __('Customer deleted.'));
    }
}
