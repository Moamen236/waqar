<?php

use App\Http\Controllers\Api\ShippingController;
use App\Http\Controllers\Store\Account\AccountController;
use App\Http\Controllers\Store\Account\AddressController;
use App\Http\Controllers\Store\Account\OrderController as AccountOrderController;
use App\Http\Controllers\Store\Account\SettingsController;
use App\Http\Controllers\Store\Auth\LoginController;
use App\Http\Controllers\Store\Auth\PasswordResetController;
use App\Http\Controllers\Store\Auth\RegisterController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\OrderTrackingController;
use App\Http\Controllers\Store\PageController;
use App\Http\Controllers\Store\ProductController;
use App\Http\Controllers\Store\SearchController;
use App\Http\Controllers\Store\ShopController;
use App\Http\Controllers\Store\WishlistController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront routes (Section 08/13/17, Phase 5)
|--------------------------------------------------------------------------
| The customer-facing half of the system. Loaded from routes/web.php
| alongside routes/admin.php, per CLAUDE.md's routing convention.
|
| Not here, deliberately: /compare (Q3), /store-list (Q4), and every
| /blog* route — all dropped from the Anvogue template rather than ported
| (Section 17). The {locale} prefix (/ar/…, /en/…) is Phase 6 and wraps
| this whole file when it lands; nothing here hard-codes a URL, so that
| stays a routing change.
*/

Route::get('/', HomeController::class)->name('home');

// Catalog
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/category/{slug}', [ShopController::class, 'category'])->name('shop.category');
Route::get('/collection/{slug}', [ShopController::class, 'collection'])->name('shop.collection');
Route::get('/search', [SearchController::class, 'index'])->name('search.index');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');
// Canonical product URL carries both: the slug for a readable link, the SKU
// (unique, and unchanged when a product is renamed) for the lookup. The old
// slug-only URL stays alive as a permanent redirect so shared links survive.
Route::get('/product/{slug}/{sku}', [ProductController::class, 'show'])->name('product.show');
Route::get('/product/{slug}', [ProductController::class, 'legacy'])->name('product.legacy');

// Cart — guests and signed-in customers alike (Section 08)
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::get('/cart/summary', [CartController::class, 'summary'])->name('cart.summary');
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::patch('/cart/{item}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
Route::post('/cart/coupon', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
Route::delete('/cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

// Checkout — COD only (Q12), guest checkout included
Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name('checkout.success');

// Public order tracking by number + email (Q8)
Route::get('/order-tracking', [OrderTrackingController::class, 'index'])->name('order-tracking.index');
Route::post('/order-tracking', [OrderTrackingController::class, 'show'])->name('order-tracking.show');

// Static content pages (no CMS — Section 17)
Route::get('/about', [PageController::class, 'about'])->name('pages.about');
Route::get('/contact', [PageController::class, 'contact'])->name('pages.contact');
Route::post('/contact', [PageController::class, 'sendContact'])->name('pages.contact.send');
Route::get('/faqs', [PageController::class, 'faqs'])->name('pages.faqs');

// Shipping cascade + server-resolved rate (Section 11)
Route::prefix('api/shipping')->name('api.shipping.')->group(function () {
    Route::get('/governorates', [ShippingController::class, 'governorates'])->name('governorates');
    Route::get('/cities', [ShippingController::class, 'cities'])->name('cities');
    Route::get('/districts', [ShippingController::class, 'districts'])->name('districts');
    Route::get('/areas', [ShippingController::class, 'areas'])->name('areas');
    Route::post('/quote', [ShippingController::class, 'quote'])->name('quote');
});

// Customer auth — email + password only, no social/OTP login (Section 13)
Route::middleware('guest:customer')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth:customer')
    ->name('logout');

// Customer account (Section 13) — my-account.html's tabs, plus the
// Reviews / Notifications / Recently-Viewed ones the template lacks
// (Section 20 #16) and minus its Billing tab (no card data exists).
Route::middleware('auth:customer')->group(function () {
    Route::get('/account', [AccountController::class, 'dashboard'])->name('account.dashboard');
    Route::get('/account/orders', [AccountOrderController::class, 'index'])->name('account.orders');
    Route::get('/account/orders/{order}', [AccountOrderController::class, 'show'])->name('account.orders.show');
    Route::post('/account/orders/{order}/cancel', [AccountOrderController::class, 'cancel'])->name('account.orders.cancel');

    Route::get('/account/addresses', [AddressController::class, 'index'])->name('account.addresses');
    Route::post('/account/addresses', [AddressController::class, 'store'])->name('account.addresses.store');
    Route::put('/account/addresses/{address}', [AddressController::class, 'update'])->name('account.addresses.update');
    Route::delete('/account/addresses/{address}', [AddressController::class, 'destroy'])->name('account.addresses.destroy');

    Route::get('/account/settings', [SettingsController::class, 'edit'])->name('account.settings');
    Route::put('/account/settings', [SettingsController::class, 'update'])->name('account.settings.update');
    Route::put('/account/password', [SettingsController::class, 'updatePassword'])->name('account.password.update');

    Route::get('/account/reviews', [AccountController::class, 'reviews'])->name('account.reviews');
    Route::get('/account/notifications', [AccountController::class, 'notifications'])->name('account.notifications');
    Route::post('/account/notifications/read', [AccountController::class, 'markNotificationsRead'])->name('account.notifications.read');
    Route::get('/account/recently-viewed', [AccountController::class, 'recentlyViewed'])->name('account.recently-viewed');

    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');

    Route::post('/product/{slug}/reviews', [ProductController::class, 'review'])->name('product.review');
});
