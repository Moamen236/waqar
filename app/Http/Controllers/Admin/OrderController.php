<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /admin/orders/create (Section 08 Flow 2, Section 20 #13) — Customer
 * Service placing an order on a customer's behalf. Reuses
 * CreateOrderAction directly, the same Action storefront checkout will
 * call in Phase 5 — same validation, same server-side pricing, same COD
 * payment record, same stock reservation, regardless of who's placing it.
 */
class OrderController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Orders/Create', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'email', 'phone']),
            'variants' => ProductVariant::query()
                ->where('status', true)
                // whereHas() respects the product's soft-delete scope, so
                // a deleted product's variants drop out of the picker.
                // Without it `$variant->product` is null for them and this
                // screen 500s outright — a deleted product cannot be sold,
                // and should not be offerable.
                ->whereHas('product')
                ->with('product:id,name,sku')
                ->get()
                ->map(fn ($variant) => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'label' => $variant->product->getTranslation('name', app()->getLocale()).' — '.$variant->sku,
                    'price' => $variant->effectivePrice(),
                ]),
            'warehouses' => Warehouse::query()->where('is_active', true)->get(['id', 'name']),
            'geoTree' => GeoTree::tree(),
        ]);
    }

    public function store(Request $request, CreateOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'governorate_id' => ['required', 'exists:governorates,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'area_id' => ['required', 'exists:areas,id'],
            'address_line' => ['required', 'string', 'max:500'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ]);

        $order = $action->execute(
            customer: Customer::findOrFail($data['customer_id']),
            items: $data['items'],
            warehouse: Warehouse::findOrFail($data['warehouse_id']),
            governorateId: (int) $data['governorate_id'],
            cityId: (int) $data['city_id'],
            districtId: isset($data['district_id']) ? (int) $data['district_id'] : null,
            areaId: (int) $data['area_id'],
            addressLine: $data['address_line'],
            recipientName: $data['recipient_name'],
            phone: $data['phone'],
            orderSource: OrderSource::CustomerService,
            createdByEmployee: $request->user('employee'),
            couponCode: $data['coupon_code'] ?? null,
        );

        return redirect()
            ->route('admin.checking.index')
            ->with('success', "Order #{$order->order_number} created for {$order->customer->name}.");
    }
}
