<?php

use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

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

/**
 * An employee who can reach an admin page, for the locale-switcher tests
 * below. Roles/permissions are seeded here rather than in a file-wide
 * beforeEach so the storefront tests above stay as fast as they were.
 *
 * @return array{0: Employee}
 */
function p6Employee(string $role = 'Vice Chairman'): array
{
    (new PermissionSeeder)->run();

    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e6-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return [$employee];
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

    $this->get(route('product.show', ['slug' => 'probe-tee', 'sku' => 'PROBE-TEE']))->assertOk();
    $this->get('/ar/product/probe-tee/PROBE-TEE')->assertOk();
});

it('translates server-side strings, not just the React UI', function () {
    $customer = Customer::create([
        'name' => 'Shopper', 'email' => 'i18n@waqar.test',
        'phone' => '01000000000', 'password' => 'password123!',
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
        ->and(route('product.show', ['slug' => 'linen-dress', 'sku' => 'LD-01'], absolute: false))->toBe('/ar/product/linen-dress/LD-01');

    URL::defaults(['locale' => 'en']);
    expect(route('shop.index', absolute: false))->toBe('/en/shop');
});

/*
|--------------------------------------------------------------------------
| Admin locale switcher — Q2's "Arabic-only for v1" turned on for staff
|--------------------------------------------------------------------------
|
| Phase 6 kept the English admin catalog complete alongside the Arabic one
| precisely so this stayed a UI change rather than a translation project.
| These assert the switcher has something correct to point at.
*/

it('gives the admin the sibling URLs its locale switcher navigates to', function () {
    [$employee] = p6Employee();

    $this->actingAs($employee, 'employee')
        ->get('/ar/admin/products')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('locale.current', 'ar')
            ->where('locale.alternates.en', '/en/admin/products')
            ->where('locale.alternates.ar', '/ar/admin/products')
        );
});

it('keeps admin query strings across a language switch', function () {
    [$employee] = p6Employee();

    // Switching language mid-filter must keep the filter — the alternates
    // are built by swapping the first path segment, not by re-routing.
    $this->actingAs($employee, 'employee')
        ->get('/ar/admin/products?q=shirt')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('locale.alternates.en', '/en/admin/products?q=shirt'));
});

it('offers the switcher on the admin login page, before anyone has signed in', function () {
    // An employee who does not read Arabic cannot reach the topbar
    // switcher without first getting through this page.
    $this->get('/en/admin/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('locale.current', 'en')
            ->where('locale.alternates.ar', '/ar/admin/login')
        );
});

it('renders the whole admin in English, not just its chrome', function () {
    [$employee] = p6Employee();

    // /en/admin has routed since Phase 6; the switcher only exposes it.
    // This asserts the page it exposes is actually usable in English.
    $this->actingAs($employee, 'employee')
        ->get('/en/admin/products')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false)
        ->assertSee('css/app.min.css', false)
        ->assertDontSee('app-rtl.min.css', false);
});

it('persists a switched language so a later bare URL lands in it', function () {
    [$employee] = p6Employee();

    // SetLocale queues the preference cookie on every localised request,
    // so choosing English once survives to the next bare-URL visit.
    $response = $this->actingAs($employee, 'employee')->get('/en/admin/products');

    $response->assertOk()->assertCookie('locale', 'en');
});
