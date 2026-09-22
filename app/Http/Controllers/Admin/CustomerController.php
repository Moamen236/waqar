<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use App\Rules\PhoneNumber;
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
     *
     * Several addresses can be filed at once (home + work, …) — the form
     * posts `addresses[]`, and exactly one of them is kept as default.
     */
    public function store(Request $request): RedirectResponse
    {
        self::mergeLegacyAddress($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => PhoneNumber::rules(),
            'is_active' => ['required', 'boolean'],
            ...self::addressesRules(),
        ]);

        $addresses = self::normalizeAddresses($data['addresses']);

        $customer = Customer::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'password' => Str::password(32),
            'is_active' => $data['is_active'],
            'is_guest' => true,
        ]);

        foreach ($addresses as $address) {
            $customer->addresses()->create([
                ...$address,
                'recipient_name' => $data['name'],
                'phone' => $data['phone'],
            ]);
        }

        return redirect()->route('admin.customers.index')->with('success', __('Customer created.'));
    }

    public function edit(Customer $customer): Response
    {
        $addresses = $customer->addresses()
            ->orderByDesc('is_default')
            ->latest('id')
            ->get()
            ->map(fn (Address $address) => [
                'id' => $address->id,
                'label' => $address->label,
                'governorate_id' => $address->governorate_id,
                'city_id' => $address->city_id,
                'district_id' => $address->district_id,
                'area_id' => $address->area_id,
                'address_line' => $address->address_line,
                'is_default' => (bool) $address->is_default,
            ])
            ->values()
            ->all();

        return Inertia::render('Customers/Form', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'is_active' => $customer->is_active,
                'addresses' => $addresses,
            ],
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        self::mergeLegacyAddress($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Nullable, not required: a customer added from the dashboard
            // (store() above) has none, and editing their phone number
            // shouldn't be blocked on inventing one.
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => PhoneNumber::rules(),
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['required', 'boolean'],
            ...self::addressesRules(true),
        ]);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $addresses = self::normalizeAddresses($data['addresses']);
        unset($data['addresses']);

        $customer->update($data);

        // Full sync: update rows that still carry an id of this customer,
        // create brand-new rows, drop rows removed in the form.
        $keptIds = [];
        foreach ($addresses as $address) {
            $payload = [
                ...$address,
                'recipient_name' => $customer->name,
                'phone' => $customer->phone,
            ];

            if (! empty($address['id'])) {
                /** @var Address|null $existing */
                $existing = $customer->addresses()->whereKey($address['id'])->first();
                if ($existing) {
                    $existing->update($payload);
                    $keptIds[] = $existing->id;

                    continue;
                }
            }

            unset($payload['id']);
            $created = $customer->addresses()->create($payload);
            $keptIds[] = $created->id;
        }

        $customer->addresses()->whereNotIn('id', $keptIds)->delete();

        return redirect()->route('admin.customers.index')->with('success', __('Customer updated.'));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->delete();

        return redirect()->route('admin.customers.index')->with('success', __('Customer deleted.'));
    }

    /**
     * Shared validation for the multi-address list the form posts.
     * update() additionally allows each row to carry its own id so
     * existing rows can be matched on sync.
     *
     * @return array<string, mixed>
     */
    private static function addressesRules(bool $withIds = false): array
    {
        return [
            'addresses' => ['required', 'array', 'min:1', 'max:10'],
            'addresses.*.id' => [$withIds ? 'nullable' : 'prohibited', 'integer', 'exists:addresses,id'],
            'addresses.*.label' => ['nullable', 'string', 'max:50'],
            'addresses.*.governorate_id' => ['required', 'exists:governorates,id'],
            'addresses.*.city_id' => ['required', 'exists:cities,id'],
            'addresses.*.district_id' => ['nullable', 'exists:districts,id'],
            'addresses.*.area_id' => ['required', 'exists:areas,id'],
            'addresses.*.address_line' => ['required', 'string', 'max:500'],
            'addresses.*.is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Guarantee exactly one default: keep the first row flagged default,
     * otherwise fall back to the first row — the storefront and delivery
     * assignment both read through is_default.
     *
     * @param  array<int, array<string, mixed>>  $addresses
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeAddresses(array $addresses): array
    {
        $hasDefault = false;
        foreach ($addresses as $i => $address) {
            $isDefault = ! empty($address['is_default']);
            if ($isDefault && ! $hasDefault) {
                $hasDefault = true;
                $addresses[$i]['is_default'] = true;
            } else {
                $addresses[$i]['is_default'] = false;
            }
        }

        if (! $hasDefault && count($addresses) > 0) {
            $addresses[0]['is_default'] = true;
        }

        return array_values($addresses);
    }

    /**
     * Back-compat for the previous single-`address` payload (pre
     * multi-address form): fold it into a one-row list so old clients
     * keep working.
     */
    private static function mergeLegacyAddress(Request $request): void
    {
        $single = $request->input('address');
        if (! $request->has('addresses') && is_array($single)) {
            $request->merge(['addresses' => [array_merge($single, ['is_default' => true])]]);
        }
    }
}
