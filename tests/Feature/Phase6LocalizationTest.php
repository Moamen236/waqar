<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Support\Facades\URL;

// Phase 6 — i18n & RTL (WAQAR-DELIVERY-ROADMAP.html). The roadmap's bar:
// "switching locale flips direction and every string (DB content + static
// UI) on both storefront and admin, with no hard-coded English left in
// Arabic mode."
//
// p6* prefix: Pest loads every Feature file into one global namespace.

function p6Product(string $en, string $ar, string $slug): Product
{
    return Product::create([
        'name' => ['en' => $en, 'ar' => $ar],
        'description' => ['en' => "About {$en}", 'ar' => "عن {$ar}"],
        'slug' => $slug,
        'sku' => strtoupper($slug),
        'price' => 250,
        'status' => true,
    ]);
}

it('redirects a locale-less URL into the default language, preserving the rest of the path', function () {
    $this->get('/')->assertRedirect('/ar');
    $this->get('/shop')->assertRedirect('/ar/shop');
    $this->get('/cart')->assertRedirect('/ar/cart');
});

it('honours a previously chosen language when deciding where a bare URL lands', function () {
    $this->withCookie('locale', 'en')->get('/shop')->assertRedirect('/en/shop');
    $this->withCookie('locale', 'ar')->get('/shop')->assertRedirect('/ar/shop');

    // An unsupported cookie value is ignored rather than trusted.
    $this->withCookie('locale', 'fr')->get('/shop')->assertRedirect('/ar/shop');
});

it('rejects an unsupported locale segment instead of quietly falling back', function () {
    // /fr/shop must not render Arabic under a URL claiming otherwise.
    $this->get('/fr/shop')->assertRedirect('/ar/fr/shop');
    $this->get('/ar/fr/shop')->assertNotFound();
});

it('does not prepend a second locale to a path that already has one', function () {
    // The catch-all would otherwise loop: /ar/nope -> /ar/ar/nope -> …
    $this->get('/ar/nope')->assertNotFound();
    $this->get('/en/nope')->assertNotFound();
});

it('drives the document language and direction from the URL, not from a session', function () {
    $this->get('/ar')->assertOk()->assertSee('<html lang="ar" dir="rtl"', false);
    $this->get('/en')->assertOk()->assertSee('<html lang="en" dir="ltr"', false);
});

it('renders translatable database content in the locale the URL asks for', function () {
    $category = Category::create([
        'name' => ['en' => 'Dresses', 'ar' => 'فساتين'],
        'slug' => 'dresses', 'status' => true,
    ]);
    p6Product('Linen Dress', 'فستان كتان', 'linen-dress')->categories()->attach($category->id);

    $this->get('/en/shop')->assertOk()
        ->assertInertia(fn ($page) => $page->where('products.0.name', 'Linen Dress'));

    $this->get('/ar/shop')->assertOk()
        ->assertInertia(fn ($page) => $page->where('products.0.name', 'فستان كتان'));
});

it('shares the locale and the sibling URLs the switcher navigates to', function () {
    $this->get('/en/shop?sort=priceLowToHigh')
        ->assertInertia(fn ($page) => $page
            ->where('locale.current', 'en')
            ->where('locale.direction', 'ltr')
            // Switching language mid-search must keep the search.
            ->where('locale.alternates.ar', '/ar/shop?sort=priceLowToHigh')
            ->where('locale.alternates.en', '/en/shop?sort=priceLowToHigh'));

    $this->get('/ar')->assertInertia(fn ($page) => $page
        ->where('locale.current', 'ar')
        ->where('locale.direction', 'rtl'));
});

it('keeps controller arguments correct even though every route gained a leading segment', function () {
    // Laravel passes route parameters to controller actions positionally,
    // so an un-forgotten {locale} would arrive as the slug and 404 every
    // product. SetLocale forgets it after use; this is the regression test.
    p6Product('Probe Tee', 'تي شيرت', 'probe-tee');

    $this->get(route('product.show', 'probe-tee'))->assertOk();
    $this->get('/ar/product/probe-tee')->assertOk();
});

it('translates server-side strings, not just the React UI', function () {
    $customer = Customer::create([
        'name' => 'Shopper', 'email' => 'i18n@waqar.test',
        'phone' => '+201000000000', 'password' => 'password123!',
    ]);

    // Validation messages come from lang/{locale}/validation.php…
    $this->withLocale('ar')
        ->post('/ar/login', ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email' => 'حقل البريد الإلكتروني مطلوب.']);

    $this->flushSession();

    // …and auth lines from lang/{locale}/auth.php.
    $this->withLocale('ar')
        ->post('/ar/login', ['email' => $customer->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => 'بيانات الدخول هذه لا تطابق سجلاتنا.']);

    $this->flushSession();

    $this->withLocale('en')
        ->post('/en/login', ['email' => '', 'password' => ''])
        ->assertSessionHasErrors(['email' => 'The email field is required.']);
});

it('serves the admin area under the same locale mechanism as the storefront', function () {
    // Section 16: "never a separate architecture" — admin gets the same
    // {locale} prefix even though only Arabic is turned on for staff (Q2).
    $this->get('/ar/admin/login')->assertOk()->assertSee('<html lang="ar" dir="rtl"', false);
    $this->get('/en/admin/login')->assertOk()->assertSee('<html lang="en" dir="ltr"', false);

    // An unauthenticated admin hit redirects to the employee login *in the
    // same language*, not the customer one and not the default locale.
    $this->get('/en/admin')->assertRedirect('/en/admin/login');
    $this->get('/ar/admin')->assertRedirect('/ar/admin/login');
});

it('loads Larkon\'s RTL stylesheet for Arabic and its LTR one for English', function () {
    // app.min.css is the LTR build; rendering it under dir=rtl is what
    // broke the admin layout before this phase.
    $this->get('/ar/admin/login')->assertSee('app-rtl.min.css', false)->assertDontSee('css/app.min.css', false);
    $this->get('/en/admin/login')->assertSee('css/app.min.css', false)->assertDontSee('app-rtl.min.css', false);
});

it('keeps route() inside the visitor\'s language without being told', function () {
    URL::defaults(['locale' => 'ar']);
    expect(route('shop.index', absolute: false))->toBe('/ar/shop')
        ->and(route('product.show', 'linen-dress', absolute: false))->toBe('/ar/product/linen-dress');

    URL::defaults(['locale' => 'en']);
    expect(route('shop.index', absolute: false))->toBe('/en/shop');
});
