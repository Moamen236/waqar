<?php

namespace App\Http\Controllers\Store;

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\OrderSource;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\Cart\CartService;
use App\Support\GeoTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Checkout — Anvogue's checkout2.html (Section 17's chosen base;
 * checkout.html is unused). Rebuilt rather than wired, per the audit:
 *
 *  - every non-COD payment option and every card-number/expiry/CVV field
 *    is gone (Question 12 — COD is the only method, and no cardholder
 *    data ever reaches this application);
 *  - the generic Country/State/City/Zip fields are replaced by the
 *    Governorate → City → District → Area cascade (Section 08/11), with
 *    Postal Code dropped entirely;
 *  - "Pickup in store" is gone — there are no retail locations in this
 *    system (Question 4).
 *
 * Order creation itself goes through the same CreateOrderAction that
 * Customer Service's /admin/orders/create calls (Section 03 — Flow 1 and
 * Flow 2 converge), so pricing, shipping resolution, coupon validation,
 * stock reservation and the COD payment record are identical either way.
 * Nothing about the total is accepted from this request.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $customer = $request->user('customer');
        $cart = $this->carts->current($request);
        $summary = $this->carts->summary($cart, customer: $customer, couponCode: $request->session()->get(CartService::COUPON_KEY));

        if ($summary['items'] === []) {
            return redirect()->route('cart.index')->with('error', __('Your cart is empty.'));
        }

        return Inertia::render('Checkout/Index', [
            'cart' => $summary,
            'countries' => GeoTree::countries(),
            'customer' => $customer ? [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ] : null,
            'addresses' => $customer
                ? Address::where('customer_id', $customer->id)->get()
                : [],
        ]);
    }

    public function store(Request $request, CreateOrderAction $action): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'governorate_id' => ['required', 'exists:governorates,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'district_id' => ['nullable', 'exists:districts,id'],
            'area_id' => ['required', 'exists:areas,id'],
            'address_line' => ['required', 'string', 'max:500'],
            'save_address' => ['nullable', 'boolean'],
        ]);

        $cart = $this->carts->current($request);
        $summary = $this->carts->summary($cart, customer: $request->user('customer'));

        if ($summary['items'] === []) {
            return redirect()->route('cart.index')->with('error', __('Your cart is empty.'));
        }

        $warehouse = Warehouse::where('is_active', true)->orderBy('id')->first();
        if ($warehouse === null) {
            return back()->with('error', __('Ordering is temporarily unavailable. Please try again shortly.'));
        }

        $customer = $request->user('customer') ?? $this->guestCustomer($data);

        try {
            $order = $action->execute(
                customer: $customer,
                items: array_map(
                    fn (array $item) => ['product_variant_id' => $item['variant_id'], 'quantity' => $item['quantity']],
                    $summary['items'],
                ),
                warehouse: $warehouse,
                governorateId: (int) $data['governorate_id'],
                cityId: (int) $data['city_id'],
                districtId: isset($data['district_id']) ? (int) $data['district_id'] : null,
                areaId: (int) $data['area_id'],
                addressLine: $data['address_line'],
                recipientName: $data['name'],
                phone: $data['phone'],
                orderSource: OrderSource::Website,
                couponCode: $request->session()->get(CartService::COUPON_KEY),
            );
        } catch (InsufficientStockException $e) {
            return back()->with('error', __('One of your items sold out while you were checking out. Please review your cart.'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($request->boolean('save_address') && $request->user('customer') !== null) {
            Address::create([
                'customer_id' => $customer->id,
                'label' => 'Home',
                'recipient_name' => $data['name'],
                'phone' => $data['phone'],
                'governorate_id' => $data['governorate_id'],
                'city_id' => $data['city_id'],
                'district_id' => $data['district_id'] ?? null,
                'area_id' => $data['area_id'],
                'address_line' => $data['address_line'],
                'is_default' => Address::where('customer_id', $customer->id)->doesntExist(),
            ]);
        }

        $cart->items()->delete();
        $request->session()->forget(CartService::COUPON_KEY);
        // A guest's order stays reachable through /order-tracking; this
        // lets the confirmation page show it once without a login.
        $request->session()->put('placed_order_number', $order->order_number);

        return redirect()->route('checkout.success', ['order' => $order->order_number]);
    }

    public function success(Request $request, int $order): Response
    {
        $model = Order::where('order_number', $order)->with('items')->firstOrFail();

        $customer = $request->user('customer');
        $ownsOrder = $customer !== null && $model->customer_id === $customer->id;
        $justPlaced = $request->session()->get('placed_order_number') === $model->order_number;

        abort_unless($ownsOrder || $justPlaced, 403);

        return Inertia::render('Checkout/Success', [
            'order' => [
                'order_number' => $model->order_number,
                'total' => (float) $model->total,
                'customer_status' => $model->customer_status->value,
                'items' => $model->items->map(fn ($item) => [
                    'name' => $item->product_name_snapshot,
                    'sku' => $item->variant_sku_snapshot,
                    'quantity' => $item->quantity,
                    'subtotal' => (float) $item->subtotal,
                ]),
            ],
        ]);
    }

    /**
     * Guest checkout (the roadmap's own "a guest can browse, add to cart,
     * check out COD-only" bar). orders.customer_id is not nullable, so a
     * guest order still attaches to a customer record.
     *
     * Which record depends on who owns the email:
     *
     *  - nobody → create a guest record (is_guest, generated password that
     *    can't be logged into until its owner resets it);
     *  - an existing *guest* record → reuse it, so someone who orders as a
     *    guest repeatedly doesn't accumulate duplicates and can still
     *    claim the account later by registering;
     *  - an existing *registered* account → refuse, and ask them to sign
     *    in. Attaching here instead would let anyone who knows an email
     *    address drop COD orders into that person's order history.
     *
     * @param  array<string, mixed>  $data
     */
    private function guestCustomer(array $data): Customer
    {
        $existing = Customer::where('email', $data['email'])->first();

        if ($existing !== null) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages([
                    'email' => __('This account is not active. Please contact support.'),
                ]);
            }

            if (! $existing->is_guest) {
                throw ValidationException::withMessages([
                    'email' => __('That email already has an account — please sign in to place this order.'),
                ]);
            }

            // Keep the guest record's contact details current; the person
            // typing them now is the one the courier will be calling.
            $existing->update(['name' => $data['name'], 'phone' => $data['phone']]);

            return $existing;
        }

        return Customer::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Str::password(32),
            'is_guest' => true,
        ]);
    }
}
