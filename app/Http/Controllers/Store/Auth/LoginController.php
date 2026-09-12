<?php

namespace App\Http\Controllers\Store\Auth;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customer login — Anvogue's login.html. Email + password only; the
 * template ships no social-login buttons, which already matches the
 * "no social/OTP login" rule (Section 13). Mirrors the employee-side
 * Admin\Auth\LoginController, but on the `customer` guard — the two
 * identities never share a table or a session user (Section 15, Q19).
 */
class LoginController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('customer')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $customer = Auth::guard('customer')->user();

        if (! $customer->is_active) {
            Auth::guard('customer')->logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated.',
            ]);
        }

        // Guest cart handover (Section 08) — has to happen before the
        // session is regenerated, while the guest cart token is still
        // readable from it.
        $this->carts->mergeGuestCart($request, $customer);

        $request->session()->regenerate();

        return redirect()->intended(route('account.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
