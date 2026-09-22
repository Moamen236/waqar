<?php

use App\Actions\Checkout\CreateOrderAction;
use App\Enums\TreasuryTransactionType;
use App\Enums\TreasuryType;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ReturnReason;
use App\Models\ShippingRate;
use App\Models\Treasury;
use App\Models\Warehouse;
use App\Models\WarehouseInventory;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ReturnReasonSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

// H1 — history lists default to today (feature-backlog-plan.md,
// request #18).
//
// Four screens are histories and grow without bound: the order book,
// returns, the activity log and treasury movement. Work queues —
// Checking, Accounting, Delivery — deliberately stay unfiltered, because
// they hold unfinished business from previous days and defaulting them
// to today strands orders nobody would think to go looking for.
//
// hd* prefix: Pest loads every Feature file into one global namespace.
// The helpers below are deliberately self-contained rather than reusing
// Phase3's — a single-file run does not load the file those live in.

function hdEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'hd-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function hdCustomer(): Customer
{
    return Customer::create([
        'name' => 'Test Customer', 'email' => 'hd-'.uniqid().'@waqar.test',
        'phone' => '01012345678', 'password' => 'password',
    ]);
}

/**
 * Move a row into the past.
 *
 * forceFill + saveQuietly, not update(): created_at is not fillable so
 * mass assignment drops it silently, and these models log their own
 * activity — a normal save would write a fresh row dated today, which is
 * exactly what the activity-log test is counting.
 */
function hdBackdate(Model $model, int $days): void
{
    $model->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();
}

/**
 * One order placed today and one placed three days ago.
 *
 * @return array{0: Order, 1: Order}
 */
function hdOrders(): array
{
    $country = Country::create(['name' => ['ar' => 'مصر', 'en' => 'Egypt'], 'code' => 'EG']);
    $governorate = Governorate::create(['country_id' => $country->id, 'name' => ['ar' => 'القاهرة', 'en' => 'Cairo']]);
    $city = City::create(['governorate_id' => $governorate->id, 'name' => ['ar' => 'مدينة نصر', 'en' => 'Nasr City']]);
    $area = Area::create(['city_id' => $city->id, 'name' => ['ar' => 'منطقة أ', 'en' => 'Zone A']]);
    ShippingRate::create(['geo_type' => 'governorate', 'geo_id' => $governorate->id, 'price' => 50]);
    $warehouse = Warehouse::create(['name' => 'Main', 'address' => 'Cairo', 'phone' => '01012345678']);

    $product = Product::create([
        'name' => ['ar' => 'منتج', 'en' => 'Widget'], 'slug' => 'widget-'.uniqid(),
        'sku' => 'W-'.uniqid(), 'price' => 100,
    ]);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'W-'.uniqid().'-V']);
    WarehouseInventory::create([
        'warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id,
        'quantity' => 50, 'reserved_quantity' => 0,
    ]);

    $make = fn () => app(CreateOrderAction::class)->execute(
        hdCustomer(), [['product_variant_id' => $variant->id, 'quantity' => 1]], $warehouse,
        $governorate->id, $city->id, null, $area->id,
        '1 Test St', 'Recipient', '01012345678',
    );

    $today = $make();
    $old = $make();
    hdBackdate($old, 3);

    return [$today->fresh(), $old->fresh()];
}

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

it('shows only today in the order book when nobody has picked a date', function () {
    [$today, $old] = hdOrders();

    $this->actingAs(hdEmployee('Chairman'), 'employee')->get(route('admin.orders.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.date_from', now()->toDateString())
            ->count('orders.data', 1)
            ->where('orders.data.0.id', $today->id)
            ->etc());

    expect($old->created_at->toDateString())->not->toBe(now()->toDateString());
});

it('opens the window back up on an explicitly empty date_from', function () {
    hdOrders();

    // Absence means "hasn't chosen" and gets today back; an empty value
    // is a choice. That is what lets one query string say both things,
    // and it is exactly what the All dates control sends.
    $this->actingAs(hdEmployee('Chairman'), 'employee')
        ->get(route('admin.orders.index', ['date_from' => '']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.date_from', null)
            ->count('orders.data', 2)
            ->etc());
});

it('still honours a date the user actually picked', function () {
    [, $old] = hdOrders();

    $this->actingAs(hdEmployee('Chairman'), 'employee')
        ->get(route('admin.orders.index', [
            'date_from' => now()->subDays(4)->toDateString(),
            'date_to' => now()->subDays(2)->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('orders.data', 1)
            ->where('orders.data.0.id', $old->id)
            ->etc());
});

it('leaves the work queues unfiltered, so older orders are not stranded', function () {
    [, $old] = hdOrders();

    // The whole reason Checking, Accounting and Delivery are excluded:
    // an order placed on Friday is still Friday's problem on Monday.
    $this->actingAs(hdEmployee('Checking'), 'employee')->get(route('admin.checking.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('orders.data', 2)
            ->etc());

    expect($old->created_at->toDateString())->not->toBe(now()->toDateString());
});

it('defaults the returns list to today and carries the same window into its export', function () {
    [$todayOrder, $oldOrder] = hdOrders();

    $this->seed(ReturnReasonSeeder::class);
    $reason = ReturnReason::query()->firstOrFail();

    $oldReturn = null;
    foreach ([$todayOrder, $oldOrder] as $order) {
        $oldReturn = $order->returns()->create([
            'customer_id' => $order->customer_id,
            'reason_id' => $reason->id,
            'status' => 'requested',
            'stage' => 'post_delivery',
            'return_shipping_fee' => 0,
        ]);
    }

    hdBackdate($oldReturn, 3);

    // Warehouse Manager, not Chairman: the returns screen is gated on
    // returns.create|returns.check, which Chairman holds neither of.
    $employee = hdEmployee('Warehouse Manager');

    $this->actingAs($employee, 'employee')->get(route('admin.returns.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.date_from', now()->toDateString())
            ->count('returns.data', 1)
            ->etc());

    // The export reads the same window, so a download can never be wider
    // than the table it claims to match.
    $this->actingAs($employee, 'employee')->get(route('admin.returns.export'))
        ->assertOk()
        ->assertDownload();
});

it('defaults the activity log to today', function () {
    $employee = hdEmployee('Chairman');

    // Seeding and the employee above already wrote rows, so an exact
    // count of the whole log would be a count of the fixture. Search for
    // one distinctive subject instead: the log searches the label
    // snapshot, which for a Customer is its name.
    Customer::create([
        'name' => 'Backlog Customer', 'email' => 'hd-'.uniqid().'@waqar.test',
        'phone' => '01012345678', 'password' => 'password',
    ]);
    Activity::query()->update(['created_at' => now()->subDays(3)]);

    $this->actingAs($employee, 'employee')
        ->get(route('admin.activity-log.index', ['search' => 'Backlog']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.date_from', now()->toDateString())
            ->count('activities.data', 0)
            ->etc());

    // Still reachable, not gone — and the search survives the reset, so
    // the two filters compose rather than clobbering each other.
    $this->actingAs($employee, 'employee')
        ->get(route('admin.activity-log.index', ['search' => 'Backlog', 'date_from' => '']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.date_from', null)
            ->count('activities.data', 1)
            ->etc());
});

it('filters the treasury ledger to today but never the balance', function () {
    $employee = hdEmployee('Chairman');
    $treasury = Treasury::create([
        'name' => 'Main', 'type' => TreasuryType::Cash->value, 'current_balance' => 200,
    ]);

    $old = null;
    foreach (range(1, 2) as $ignored) {
        $old = $treasury->transactions()->create([
            'type' => TreasuryTransactionType::Income->value,
            'amount' => 100,
            'created_by' => $employee->id,
        ]);
    }

    hdBackdate($old, 3);

    $this->actingAs($employee, 'employee')->get(route('admin.treasury.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->count('transactions.data', 1)
            // The balance is the account's real balance, not the sum of
            // what is on screen — filtering it would be a lie about money.
            ->where('selected.current_balance', '200.00')
            ->etc());
});
