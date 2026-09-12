<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Mail\ContactMessageMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Static content pages — Anvogue's about.html / contact.html / faqs.html.
 * Kept as real routes rather than a CMS: the "no generic CMS / page
 * builder" rule excludes a dynamic page builder, not static pages
 * (Section 17). Store Locator (store-list.html) and Product Compare
 * (compare.html) are dropped entirely (Questions 3 and 4), as is the
 * whole blog module.
 */
class PageController extends Controller
{
    public function about(): Response
    {
        return Inertia::render('Pages/About');
    }

    public function contact(): Response
    {
        return Inertia::render('Pages/Contact');
    }

    /**
     * The contact form actually sends, to the business's own support
     * inbox (config('mail.support_address')). No contact-message table is
     * created for it — Section 24 defines none, and Customer Service works
     * from the inbox rather than from a second queue inside the admin.
     */
    public function sendContact(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'order_number' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            // Honeypot: a real person never fills a hidden field in, and
            // a public form that sends mail is worth keeping bots off.
            'website' => ['prohibited'],
        ]);

        $key = 'contact-form:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'message' => 'You have sent several messages already. Please give us a little time to reply.',
            ]);
        }
        RateLimiter::hit($key, 600);

        Mail::to(config('mail.support_address'))->send(new ContactMessageMail(
            senderName: $data['name'],
            senderEmail: $data['email'],
            body: $data['message'],
            orderNumber: $data['order_number'] ?? null,
        ));

        return back()->with('success', 'Thanks — your message is with our team, and we\'ll reply by email.');
    }

    public function faqs(): Response
    {
        return Inertia::render('Pages/Faqs');
    }
}
