<?php

namespace App\Http\Controllers\Store\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Cart\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer registration — Anvogue's register.html, with the Name and
 * Phone fields the spec requires and the template omits (Section 13:
 * "Name, Email, Phone, Password, Password Confirmation").
 */
class RegisterController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Not a plain `unique:` rule — an email may already belong to a
            // *guest* record created for this person at checkout or by
            // Customer Service, which registering is allowed to claim (see
            // below). Only a real registered account blocks it.
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('customers', 'email')->where(fn ($query) => $query->where('is_guest', false)),
            ],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $customer = $this->claimGuestRecord($data) ?? Customer::create($data);

        Auth::guard('customer')->login($customer);
        $this->carts->mergeGuestCart($request, $customer);
        $request->session()->regenerate();

        return redirect()->route('account.dashboard')->with('success', __('Welcome to WAQAR.'));
    }

    /**
     * Turn the guest record created for this person at checkout into their
     * real account, rather than orphaning their past orders under a
     * duplicate row they can never sign into. Registering is the only way
     * a guest record is ever claimed, and it takes a password to do it —
     * which is what makes the record stop being reusable by the next guest
     * typing that address (see CheckoutController::guestCustomer()).
     *
     * @param  array<string, mixed>  $data
     */
    private function claimGuestRecord(array $data): ?Customer
    {
        $guest = Customer::where('email', $data['email'])->where('is_guest', true)->first();

        if ($guest === null) {
            return null;
        }

        $guest->update([...$data, 'is_guest' => false]);

        return $guest;
    }
}
