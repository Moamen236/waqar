<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use App\Models\ShippingCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/delivery/shipping-companies (Section 14) — flat per-company fee
 * config, plain CRUD.
 */
class ShippingCompanyController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:delivery.companies.view', only: ['index']),
            new Middleware('permission:delivery.companies.create', only: ['create', 'store']),
            new Middleware('permission:delivery.companies.update', only: ['edit', 'update']),
            new Middleware('permission:delivery.companies.delete', only: ['destroy']),
        ];
    }

    public function index(): Response
    {
        return Inertia::render('Delivery/ShippingCompanies/Index', [
            'shippingCompanies' => ShippingCompany::query()->latest('id')->paginate(20)->withQueryString(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Delivery/ShippingCompanies/Form', ['shippingCompany' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        ShippingCompany::create($this->validated($request));

        return redirect()->route('admin.delivery.shipping-companies.index')->with('success', __('Shipping company created.'));
    }

    public function edit(ShippingCompany $shippingCompany): Response
    {
        return Inertia::render('Delivery/ShippingCompanies/Form', ['shippingCompany' => $shippingCompany]);
    }

    public function update(Request $request, ShippingCompany $shippingCompany): RedirectResponse
    {
        $shippingCompany->update($this->validated($request));

        return redirect()->route('admin.delivery.shipping-companies.index')->with('success', __('Shipping company updated.'));
    }

    public function destroy(ShippingCompany $shippingCompany): RedirectResponse
    {
        $shippingCompany->delete();

        return redirect()->route('admin.delivery.shipping-companies.index')->with('success', __('Shipping company deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'delivery_fee' => ['required', 'numeric', 'min:0'],
            'return_fee' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
