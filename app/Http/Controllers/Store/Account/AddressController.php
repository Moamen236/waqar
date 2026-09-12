<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account area's Address tab. The template's Billing/Shipping split
 * (my-account.html) is dropped — there is no billing concept in a
 * COD-only system with no card storage (Question 12) — and its generic
 * Country/State/City/ZIP text inputs are replaced by the Governorate →
 * City → District → Area cascade every address in this system uses
 * (Section 11, Section 20 #14). ZIP is dropped: it isn't part of either
 * source document's address model.
 */
class AddressController extends Controller
{
    public function index(Request $request): Response
    {
        $customer = $request->user('customer');

        return Inertia::render('Account/Addresses', [
            'addresses' => Address::where('customer_id', $customer->id)
                ->with(['governorate', 'city', 'district', 'area'])
                ->orderByDesc('is_default')
                ->get()
                ->map(fn (Address $address) => $this->present($address)),
            'countries' => GeoTree::countries(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $request->user('customer');
        $data = $this->validated($request);

        $this->persist($customer->id, $data);

        return back()->with('success', __('Address saved.'));
    }

    public function update(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeAddress($request, $address);

        $data = $this->validated($request);
        $this->persist($address->customer_id, $data, $address);

        return back()->with('success', __('Address updated.'));
    }

    public function destroy(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeAddress($request, $address);

        $address->delete();

        return back()->with('success', __('Address removed.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'governorate_id' => ['required', 'exists:governorates,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'area_id' => ['required', 'exists:areas,id'],
            'address_line' => ['required', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(int $customerId, array $data, ?Address $address = null): void
    {
        $isDefault = (bool) ($data['is_default'] ?? false);
        $data['is_default'] = $isDefault;
        $data['district_id'] = $data['district_id'] ?? null;

        if ($address === null) {
            // The first address a customer saves is their default
            // whether or not they ticked the box.
            $data['is_default'] = $isDefault || Address::where('customer_id', $customerId)->doesntExist();
            $address = Address::create([...$data, 'customer_id' => $customerId]);
        } else {
            $address->update($data);
        }

        if ($address->is_default) {
            Address::where('customer_id', $customerId)
                ->whereKeyNot($address->id)
                ->update(['is_default' => false]);
        }
    }

    private function authorizeAddress(Request $request, Address $address): void
    {
        abort_unless($address->customer_id === $request->user('customer')->id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Address $address): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'governorate_id' => $address->governorate_id,
            'city_id' => $address->city_id,
            'district_id' => $address->district_id,
            'area_id' => $address->area_id,
            'address_line' => $address->address_line,
            'is_default' => (bool) $address->is_default,
            'summary' => collect([
                $address->address_line,
                $address->area?->getTranslation('name', $locale),
                $address->district?->getTranslation('name', $locale),
                $address->city?->getTranslation('name', $locale),
                $address->governorate?->getTranslation('name', $locale),
            ])->filter()->implode(', '),
        ];
    }
}
