<?php

namespace App\Http\Controllers\Store\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The account area's Setting tab — profile fields + change password
 * (Section 13). The template's Gender / Day-of-Birth / avatar-upload
 * fields are dropped: `customers` carries name, email, phone and nothing
 * else (Section 24), and inventing columns for template decoration isn't
 * this phase's job.
 */
class SettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $customer = $request->user('customer');

        return Inertia::render('Account/Settings', [
            'profile' => [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $customer = $request->user('customer');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:customers,email,'.$customer->id],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $customer->update($data);

        return back()->with('success', __('Profile updated.'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $customer = $request->user('customer');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (! Hash::check($data['current_password'], $customer->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('That is not your current password.'),
            ]);
        }

        $customer->update(['password' => $data['password']]);

        return back()->with('success', __('Password changed.'));
    }
}
