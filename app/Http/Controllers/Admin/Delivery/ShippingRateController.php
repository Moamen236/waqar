<?php

namespace App\Http\Controllers\Admin\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\City;
use App\Models\District;
use App\Models\Governorate;
use App\Models\ShippingRate;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/delivery/shipping-rates — the level-flexible shipping rate table
 * (Section 11, §20 #20, Q10). Without at least one row here the storefront
 * checkout refuses every order by design, so this is the screen that makes
 * the store sellable; Phase 5 shipped without it and seeded rates instead.
 *
 * A rate is attached to one level of the geography — governorate, city,
 * district or area — and ShippingRateResolver picks the most specific
 * configured match at checkout (Area → District → City → Governorate).
 * Nothing here computes a customer-facing price; it only stores what the
 * resolver will later read.
 */
class ShippingRateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Delivery/ShippingRates/Index', [
            'rates' => ShippingRate::query()
                ->orderBy('geo_type')
                ->orderBy('geo_id')
                ->paginate(20)
                ->through(fn (ShippingRate $rate) => [
                    'id' => $rate->id,
                    'geo_type' => $rate->geo_type,
                    'geo_id' => $rate->geo_id,
                    'geo_label' => self::geoLabel($rate->geo_type, $rate->geo_id),
                    'price' => (string) $rate->price,
                    'free_shipping_threshold' => $rate->free_shipping_threshold !== null
                        ? (string) $rate->free_shipping_threshold
                        : null,
                    'is_active' => $rate->is_active,
                ]),
            // Shown on the list so whoever maintains this can see at a
            // glance which areas would still reject a checkout.
            'uncoveredGovernorates' => Governorate::query()
                ->where('is_active', true)
                ->whereNotIn('id', ShippingRate::where('geo_type', 'governorate')->where('is_active', true)->pluck('geo_id'))
                ->get()
                ->map(fn (Governorate $governorate) => $governorate->getTranslation('name', app()->getLocale()))
                ->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Delivery/ShippingRates/Form', [
            'rate' => null,
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        ShippingRate::create($data);

        return redirect()->route('admin.delivery.shipping-rates.index')->with('success', 'Shipping rate created.');
    }

    public function edit(ShippingRate $shippingRate): Response
    {
        return Inertia::render('Delivery/ShippingRates/Form', [
            'rate' => [
                'id' => $shippingRate->id,
                'geo_type' => $shippingRate->geo_type,
                'geo_id' => $shippingRate->geo_id,
                'price' => (string) $shippingRate->price,
                'free_shipping_threshold' => $shippingRate->free_shipping_threshold !== null
                    ? (string) $shippingRate->free_shipping_threshold
                    : '',
                'is_active' => $shippingRate->is_active,
            ],
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function update(Request $request, ShippingRate $shippingRate): RedirectResponse
    {
        $shippingRate->update($this->validated($request, $shippingRate->id));

        return redirect()->route('admin.delivery.shipping-rates.index')->with('success', 'Shipping rate updated.');
    }

    public function destroy(ShippingRate $shippingRate): RedirectResponse
    {
        $shippingRate->delete();

        return redirect()->route('admin.delivery.shipping-rates.index')->with('success', 'Shipping rate removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'geo_type' => ['required', Rule::in(['governorate', 'city', 'district', 'area'])],
            'geo_id' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // geo_id is a polymorphic pointer, so `exists:` can't validate it
        // in one rule — check it against the table the chosen level names,
        // otherwise a rate can be attached to a row that doesn't exist and
        // simply never match anything at checkout.
        $exists = match ($data['geo_type']) {
            'governorate' => Governorate::whereKey($data['geo_id'])->exists(),
            'city' => City::whereKey($data['geo_id'])->exists(),
            'district' => District::whereKey($data['geo_id'])->exists(),
            default => Area::whereKey($data['geo_id'])->exists(),
        };

        abort_unless($exists, 422, 'That location does not exist at the chosen level.');

        // The table has a unique (geo_type, geo_id) index — catching the
        // clash here gives a readable validation error instead of a raw
        // QueryException.
        $duplicate = ShippingRate::where('geo_type', $data['geo_type'])
            ->where('geo_id', $data['geo_id'])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($duplicate) {
            abort(422, 'A rate is already configured for that location.');
        }

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $data['free_shipping_threshold'] = ($data['free_shipping_threshold'] ?? '') === '' ? null : $data['free_shipping_threshold'];

        return $data;
    }

    private static function geoLabel(string $geoType, int $geoId): string
    {
        $locale = app()->getLocale();

        $model = match ($geoType) {
            'governorate' => Governorate::find($geoId),
            'city' => City::find($geoId),
            'district' => District::find($geoId),
            default => Area::find($geoId),
        };

        return $model?->getTranslation('name', $locale) ?? "#{$geoId} (missing)";
    }
}
