<?php

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Reports\ReportRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Reporting & Analytics module
|--------------------------------------------------------------------------
|
| The module's load-bearing claims, each with a test that fails if it stops
| being true:
|
| 1. **Row scoping is inherited, not re-implemented.** Every order-rooted
|    report runs through Order::scopeVisibleTo(), so a Customer Service
|    agent's report — and its export — can only ever contain their own
|    orders. This is the one that matters most: getting it wrong leaks the
|    whole order book through a screen nobody thinks of as an order screen.
| 2. **Column gating strips, it does not zero.** A viewer without
|    reports.cost.view never receives the column at all.
| 3. **Export needs its own grant.** Seeing a report and downloading it are
|    different rights, matching the existing orders.export convention.
| 4. **The catalogue hides what you cannot open**, rather than showing
|    locked cards.
| 5. **Coupon/promotion edits are audited, but redemptions are not** — the
|    times_used counter would otherwise write an audit row per order and
|    bury the deliberate edits the trail exists to surface.
|
| rmt* prefix: Pest loads every Feature file into one global namespace.
*/

function rmtEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' Reporter',
        'email' => 'rmt-'.uniqid().'@waqar.test',
        'phone' => '01012345678',
        'password' => 'password',
        'residence_address' => 'N/A',
        'national_id_number' => '29001010100000',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function rmtOrder(OrderSource $source, ?Employee $creator = null, string $total = '100.00'): Order
{
    $country = Country::firstOrCreate(['code' => 'EG'], ['name' => ['ar' => 'مصر', 'en' => 'Egypt']]);
    $governorate = Governorate::firstOrCreate(
        ['country_id' => $country->id, 'name->en' => 'Cairo'],
        ['name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]
    );
    $city = City::firstOrCreate(
        ['governorate_id' => $governorate->id, 'name->en' => 'Nasr City'],
        ['name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]
    );
    $area = Area::firstOrCreate(
        ['city_id' => $city->id, 'name->en' => 'Zone A'],
        ['name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]
    );

    $customer = Customer::create([
        'name' => 'Report Customer',
        'email' => 'rmt-c-'.uniqid().'@waqar.test',
        'phone' => '0100'.random_int(1000000, 9999999),
        'password' => 'password',
        'is_active' => true,
    ]);

    return Order::create([
        'order_number' => random_int(100000, 999999),
        'customer_id' => $customer->id,
        'created_by_employee_id' => $creator?->id,
        'order_source' => $source,
        'status' => OrderStatus::New,
        'customer_status' => OrderStatus::New->customerStatus(),
        'payment_status' => 'pending',
        'subtotal' => $total,
        'discount_amount' => '0.00',
        'shipping_amount' => '10.00',
        'total' => $total,
        'shipping_recipient_name' => 'Report Customer',
        'shipping_phone' => '01000000000',
        'shipping_address_line' => 'Somewhere',
        'shipping_governorate_id' => $governorate->id,
        'shipping_city_id' => $city->id,
        'shipping_area_id' => $area->id,
    ]);
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('scopes an order report to the rows the employee may already see', function () {
    $agent = rmtEmployee('Customer Service');
    $other = rmtEmployee('Customer Service');

    rmtOrder(OrderSource::CustomerService, $agent, '150.00');
    rmtOrder(OrderSource::CustomerService, $other, '999.00');
    rmtOrder(OrderSource::Website, null, '500.00');

    $report = app(ReportRegistry::class)->find('orders.summary');
    $filters = ['preset' => 'this_month', 'granularity' => 'month'];

    // The agent sees only the order they created — not their colleague's,
    // and not the website order.
    $agentTotals = $report->totals($agent, $filters);
    expect($agentTotals['orders_count'])->toBe(1)
        ->and((float) $agentTotals['gross'])->toBe(150.0);

    // Store Orders is the mirror image: website orders only.
    $storeTotals = $report->totals(rmtEmployee('Store Orders'), $filters);
    expect($storeTotals['orders_count'])->toBe(1)
        ->and((float) $storeTotals['gross'])->toBe(500.0);

    // Chairman sees the whole book.
    expect($report->totals(rmtEmployee('Chairman'), $filters)['orders_count'])->toBe(3);
});

it('counts order lines without letting a multi-line order inflate its own totals', function () {
    $chairman = rmtEmployee('Chairman');
    $order = rmtOrder(OrderSource::Website, null, '300.00');

    $product = Product::create([
        'name' => ['ar' => 'منتج', 'en' => 'Product'],
        'slug' => 'rmt-'.uniqid(),
        'sku' => 'RMT-'.uniqid(),
        'price' => '50.00',
        'status' => true,
    ]);

    // Two lines, five pieces. A join to order_items instead of the
    // correlated subquery would double this order's *value* as well as its
    // unit count — the bug this test exists to catch.
    foreach ([['q' => 2, 'sub' => '100.00'], ['q' => 3, 'sub' => '200.00']] as $line) {
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'RMT-V-'.uniqid(),
            'status' => true,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => 'Snapshot',
            'variant_sku_snapshot' => $variant->sku,
            'quantity' => $line['q'],
            'unit_price' => '50.00',
            'subtotal' => $line['sub'],
        ]);
    }

    $totals = app(ReportRegistry::class)
        ->find('orders.summary')
        ->totals($chairman, ['preset' => 'this_month', 'granularity' => 'month']);

    expect($totals['orders_count'])->toBe(1)
        ->and($totals['units'])->toBe(5)
        ->and((float) $totals['gross'])->toBe(300.0);
});

it('hides reports the employee has no permission for', function () {
    $checking = rmtEmployee('Checking');
    $visible = app(ReportRegistry::class)->visibleTo($checking);

    // Checking gets orders and inventory, never finance or audit.
    expect($visible->keys()->all())->toContain('orders')
        ->and($visible->keys()->all())->not->toContain('finance')
        ->and($visible->keys()->all())->not->toContain('audit');
});

it('refuses a report whose own permission the employee lacks', function () {
    $this->actingAs(rmtEmployee('Checking'), 'employee')
        ->get(route('admin.reports.show', 'orders.summary'))
        ->assertOk();

    // Holds the module gate but not this report's group — proving the
    // per-report check in the controller bites, not just the route
    // middleware. A role-derived permission cannot be revoked off the
    // employee, so the role itself carries only reports.view.
    $gateOnly = Role::findOrCreate('Reports Gate Only', 'employee');
    $gateOnly->syncPermissions(['reports.view']);

    $limited = rmtEmployee('Reports Gate Only');

    expect($limited->can('reports.view'))->toBeTrue()
        ->and($limited->can('reports.orders.view'))->toBeFalse();

    $this->actingAs($limited, 'employee')
        ->get(route('admin.reports.show', 'orders.summary'))
        ->assertForbidden();
});

it('requires a separate grant to download what it lets you read', function () {
    $checking = rmtEmployee('Checking');

    // Checking may read orders reporting but is not granted reports.export.
    expect($checking->can('reports.orders.view'))->toBeTrue()
        ->and($checking->can('reports.export'))->toBeFalse();

    $this->actingAs($checking, 'employee')
        ->get(route('admin.reports.export', 'orders.summary'))
        ->assertForbidden();

    $this->actingAs(rmtEmployee('Chairman'), 'employee')
        ->get(route('admin.reports.export', 'orders.summary'))
        ->assertOk();
});

it('rejects an unbounded custom range rather than scanning the whole table', function () {
    $this->actingAs(rmtEmployee('Chairman'), 'employee')
        ->get(route('admin.reports.show', 'orders.summary').'?preset=custom')
        ->assertSessionHasErrors('date_from');

    $this->actingAs(rmtEmployee('Chairman'), 'employee')
        ->get(route('admin.reports.show', 'orders.summary').'?preset=custom&date_from=2020-01-01&date_to=2026-01-01')
        ->assertSessionHasErrors('date_to');
});

it('advertises only report groups that actually contain a report', function () {
    $superAdmin = rmtEmployee('Super Admin');

    // Super Admin holds every reports.* permission, so permission alone
    // would advertise all eight groups — and seven of them would open an
    // empty catalogue reading "you do not have access", which is both
    // wrong and alarming. The sidebar reads this prop instead.
    expect($superAdmin->can('reports.executive.view'))->toBeTrue();

    $this->actingAs($superAdmin, 'employee')
        ->get(route('admin.reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('groups.orders.0.key', 'orders.summary')
            ->missing('groups.executive'));

    $groups = app(ReportRegistry::class)->visibleTo($superAdmin)->keys()->all();

    expect($groups)->toContain('orders')
        ->and($groups)->not->toContain('executive');
});

it('executes every registered report without error', function () {
    $chairman = rmtEmployee('Chairman');
    rmtOrder(OrderSource::Website, null, '250.00');

    // Every report, run for real against the schema. This is the cheapest
    // possible guard on a module of this size: a report is a SQL query
    // built from string fragments, so a typo or a renamed column produces
    // a runtime error that no amount of static analysis catches — and
    // without this, it would surface when someone opened the screen.
    $filters = [
        'preset' => 'this_year',
        'granularity' => 'month',
        // Entity History needs a subject; the others ignore these.
        'subject_type' => 'Order',
        'subject_id' => 1,
    ];

    $registry = app(ReportRegistry::class);
    $failures = [];

    foreach ($registry->all() as $key => $report) {
        try {
            $rows = $report->query($chairman, $filters)->get();

            // map() is part of the contract too — a column the query never
            // selected fails here rather than on the screen.
            foreach ($rows->take(1) as $row) {
                $report->map($row);
            }

            $report->totals($chairman, $filters);
        } catch (Throwable $e) {
            $failures[$key] = $e->getMessage();
        }
    }

    expect($failures)->toBe([]);
});

it('gives every registered report a translatable title and a known group', function () {
    $catalog = json_decode(
        (string) file_get_contents(resource_path('js/admin/locales/en.json')),
        true
    );

    $missing = [];

    foreach (app(ReportRegistry::class)->all() as $key => $report) {
        foreach ([$report->title(), $report->description()] as $label) {
            if (! array_key_exists($label, $catalog)) {
                $missing[] = "{$key}: {$label}";
            }
        }

        foreach ($report->columns() as $column) {
            if (! array_key_exists($column->label, $catalog)) {
                $missing[] = "{$key}: {$column->label}";
            }
        }

        foreach ($report->notes() as $note) {
            if (! array_key_exists($note, $catalog)) {
                $missing[] = "{$key}: {$note}";
            }
        }

        expect(ReportRegistry::GROUPS)->toContain($report->group());
    }

    // An untranslated heading renders as the raw key — obvious in review,
    // invisible in a downloaded spreadsheet. Catch it here instead.
    expect($missing)->toBe([]);
});

it('keeps the Arabic and English catalogs in step', function () {
    $en = json_decode((string) file_get_contents(resource_path('js/admin/locales/en.json')), true);
    $ar = json_decode((string) file_get_contents(resource_path('js/admin/locales/ar.json')), true);

    expect(array_diff(array_keys($en), array_keys($ar)))->toBe([])
        ->and(array_diff(array_keys($ar), array_keys($en)))->toBe([]);
});

it('404s an unknown report key', function () {
    $this->actingAs(rmtEmployee('Chairman'), 'employee')
        ->get(route('admin.reports.show', 'orders.no-such-report'))
        ->assertNotFound();
});

it('audits a coupon edit but not a redemption', function () {
    $coupon = Coupon::create([
        'code' => 'RMT10',
        'type' => 'percentage',
        'value' => '10.00',
        'times_used' => 0,
        'is_active' => true,
    ]);

    $baseline = Activity::query()->where('subject_type', $coupon->getMorphClass())->count();

    // A deliberate edit: logged.
    $coupon->update(['value' => '25.00']);

    // A redemption: NOT logged. times_used is excluded precisely so this
    // does not write an audit row per order and bury the edit above.
    $coupon->increment('times_used');

    $entries = Activity::query()
        ->where('subject_type', $coupon->getMorphClass())
        ->where('subject_id', $coupon->id)
        ->get();

    expect($entries)->toHaveCount($baseline + 1);

    $latest = $entries->last();
    expect($latest->properties['attributes']['value'])->toEqual('25.00')
        ->and($latest->properties['old']['value'])->toEqual('10.00')
        ->and($latest->properties['label'])->toBe('RMT10')
        ->and($latest->log_name)->toBe('catalog')
        ->and($latest->properties['attributes'])->not->toHaveKey('times_used');
});

it('audits a promotion edit with old and new values', function () {
    $promotion = Promotion::create([
        'name' => ['ar' => 'عرض', 'en' => 'Bundle Deal'],
        'type' => 'bundle',
        'discount_type' => 'percentage',
        'discount_value' => '15.00',
        'priority' => 0,
        'times_used' => 0,
        'is_active' => true,
    ]);

    $promotion->update(['discount_value' => '30.00']);
    $promotion->increment('times_used');

    $entry = Activity::query()
        ->where('subject_type', $promotion->getMorphClass())
        ->where('subject_id', $promotion->id)
        ->latest('id')
        ->first();

    expect($entry->properties['old']['discount_value'])->toEqual('15.00')
        ->and($entry->properties['attributes']['discount_value'])->toEqual('30.00')
        ->and($entry->properties['attributes'])->not->toHaveKey('times_used');
});
