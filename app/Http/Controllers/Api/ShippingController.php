<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\City;
use App\Models\District;
use App\Models\Governorate;
use App\Services\Cart\CartService;
use App\Services\Shipping\ShippingRateResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The E-Commerce document's Shipping API endpoints, extended with the
 * districts level (Section 08's resolution, Section 20 #20). The cart
 * and checkout cascades read from these; the quote endpoint returns the
 * *server-resolved* rate and the recomputed cart totals, so the browser
 * never computes — or supplies — a shipping price.
 */
class ShippingController extends Controller
{
    public function __construct(
        private readonly ShippingRateResolver $rates,
        private readonly CartService $carts,
    ) {}

    public function governorates(): JsonResponse
    {
        return response()->json(
            Governorate::where('is_active', true)->get()->map(fn (Governorate $g) => [
                'id' => $g->id,
                'name' => $g->getTranslation('name', app()->getLocale()),
            ])
        );
    }

    public function cities(Request $request): JsonResponse
    {
        $request->validate(['governorate_id' => ['required', 'exists:governorates,id']]);

        return response()->json(
            City::where('governorate_id', $request->integer('governorate_id'))
                ->where('is_active', true)
                ->get()
                ->map(fn (City $c) => ['id' => $c->id, 'name' => $c->getTranslation('name', app()->getLocale())])
        );
    }

    public function districts(Request $request): JsonResponse
    {
        $request->validate(['city_id' => ['required', 'exists:cities,id']]);

        return response()->json(
            District::where('city_id', $request->integer('city_id'))
                ->where('status', true)
                ->get()
                ->map(fn (District $d) => ['id' => $d->id, 'name' => $d->getTranslation('name', app()->getLocale())])
        );
    }

    public function areas(Request $request): JsonResponse
    {
        $request->validate([
            'city_id' => ['required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
        ]);

        return response()->json(
            Area::where('city_id', $request->integer('city_id'))
                ->when($request->filled('district_id'), fn ($q) => $q->where('district_id', $request->integer('district_id')))
                ->where('is_active', true)
                ->get()
                ->map(fn (Area $a) => ['id' => $a->id, 'name' => $a->getTranslation('name', app()->getLocale())])
        );
    }

    /**
     * Resolved shipping price + the recomputed cart totals for it
     * (Section 11's Area → District → City → Governorate fallback). A
     * null `shipping` means no rate is configured anywhere up that chain
     * — checkout will refuse the order rather than ship it for free.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'governorate_id' => ['required', 'exists:governorates,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'area_id' => ['nullable', 'exists:areas,id'],
        ]);

        $cart = $this->carts->current($request);
        $customer = $request->user('customer');
        $summary = $this->carts->summary($cart, customer: $customer);

        $rate = $this->rates->resolve(
            (int) $data['governorate_id'],
            isset($data['city_id']) ? (int) $data['city_id'] : null,
            isset($data['district_id']) ? (int) $data['district_id'] : null,
            isset($data['area_id']) ? (int) $data['area_id'] : null,
        );

        $shipping = null;
        if ($rate !== null) {
            $shipping = (float) $rate->price;
            if ($rate->free_shipping_threshold !== null && $summary['subtotal'] >= (float) $rate->free_shipping_threshold) {
                $shipping = 0.0;
            }
        }

        return response()->json($this->carts->summary(
            $cart,
            shipping: $shipping,
            customer: $customer,
            couponCode: $request->session()->get(CartService::COUPON_KEY),
        ));
    }
}
