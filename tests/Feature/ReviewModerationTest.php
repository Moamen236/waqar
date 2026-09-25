<?php

use App\Enums\ReviewStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Review;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

// Admin moderation of customer reviews (/admin/reviews). Helpers are
// self-contained (rm* prefix) so this file runs on its own.

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
});

function rmEmployee(string $role): Employee
{
    $employee = Employee::create([
        'full_name' => $role.' User', 'email' => 'e-'.uniqid().'@waqar.test', 'phone' => '01012345678',
        'password' => 'password', 'residence_address' => 'N/A', 'national_id_number' => 'N/A',
    ]);
    $employee->assignRole(Role::findOrCreate($role, 'employee'));

    return $employee;
}

function rmReview(array $attributes = []): Review
{
    $product = $attributes['product'] ?? Product::create([
        'name' => ['en' => 'Linen Shirt', 'ar' => 'قميص كتان'],
        'slug' => 'linen-shirt-'.uniqid(),
        'sku' => 'LS-'.uniqid(),
        'price' => 300,
        'status' => true,
    ]);
    $customer = Customer::create([
        'name' => $attributes['customer'] ?? 'Mona', 'email' => 'c-'.uniqid().'@waqar.test',
        'phone' => '010'.random_int(10000000, 99999999), 'password' => 'password',
    ]);

    return Review::create([
        'product_id' => $product->id,
        'customer_id' => $customer->id,
        'rating' => $attributes['rating'] ?? 4,
        'title' => $attributes['title'] ?? 'Great fit',
        'comment' => $attributes['comment'] ?? 'Soft fabric, true to size.',
    ]);
}

it('lists the pending queue by default, oldest first, with a count per status', function () {
    $moderator = rmEmployee('Vice Chairman');
    $older = rmReview(['title' => 'First in']);
    $newer = rmReview(['title' => 'Second in']);
    $approved = rmReview();
    $approved->update(['status' => ReviewStatus::Approved]);

    $this->actingAs($moderator, 'employee')
        ->get(route('admin.reviews.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reviews/Index')
            ->where('filters.status', 'pending')
            ->where('reviews.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$older->id, $newer->id])
            ->where('counts', ['pending' => 2, 'approved' => 1, 'rejected' => 0])
            ->etc());
});

it('filters by rating, verified purchase, product and search text', function () {
    $moderator = rmEmployee('Vice Chairman');
    $low = rmReview(['rating' => 1, 'comment' => 'Stitching came apart']);
    rmReview(['rating' => 5]);

    $ids = fn (array $query) => collect(
        $this->actingAs($moderator, 'employee')->get(route('admin.reviews.index', $query))->viewData('page')['props']['reviews']['data'],
    )->pluck('id')->all();

    expect($ids(['rating' => 1]))->toBe([$low->id])
        ->and($ids(['search' => 'stitching']))->toBe([$low->id])
        ->and($ids(['product_id' => $low->product_id]))->toBe([$low->id])
        // Neither review is linked to an order.
        ->and($ids(['verified' => '1']))->toBe([]);
});

it('approves a review, publishing it, and records who did it with their note', function () {
    $moderator = rmEmployee('Vice Chairman');
    $review = rmReview();

    $this->actingAs($moderator, 'employee')
        ->post(route('admin.reviews.approve', $review), ['note' => 'Genuine, helpful.'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($review->fresh()->status)->toBe(ReviewStatus::Approved);

    $entry = Activity::query()->where('subject_type', $review->getMorphClass())->where('subject_id', $review->id)->sole();
    expect($entry->event)->toBe('approved')
        ->and($entry->causer_id)->toBe($moderator->id)
        ->and($entry->properties['old']['status'])->toBe('pending')
        ->and($entry->properties['note'])->toBe('Genuine, helpful.');

    // And the detail page reads that back as history.
    $this->actingAs($moderator, 'employee')
        ->get(route('admin.reviews.show', $review))
        ->assertInertia(fn ($page) => $page
            ->component('Reviews/Show')
            ->where('review.status', 'approved')
            ->where('history.0.to', 'approved')
            ->where('history.0.note', 'Genuine, helpful.')
            ->where('history.0.by', $moderator->full_name)
            ->etc());
});

it('can reverse a decision, but never sends a review back to pending', function () {
    $moderator = rmEmployee('Vice Chairman');
    $review = rmReview();

    $this->actingAs($moderator, 'employee')->post(route('admin.reviews.approve', $review));
    $this->actingAs($moderator, 'employee')->post(route('admin.reviews.reject', $review), ['note' => 'Spam link']);

    expect($review->fresh()->status)->toBe(ReviewStatus::Rejected)
        ->and(Activity::query()->where('subject_id', $review->id)->where('subject_type', $review->getMorphClass())->count())->toBe(2);

    $this->actingAs($moderator, 'employee')
        ->post(route('admin.reviews.bulk'), ['ids' => [$review->id], 'decision' => 'pending'])
        ->assertSessionHasErrors('decision');
});

it('decides several reviews at once, skipping ones already in that state', function () {
    $moderator = rmEmployee('Vice Chairman');
    $a = rmReview();
    $b = rmReview();
    $already = rmReview();
    $already->update(['status' => ReviewStatus::Approved]);

    $this->actingAs($moderator, 'employee')
        ->post(route('admin.reviews.bulk'), ['ids' => [$a->id, $b->id, $already->id], 'decision' => 'approved'])
        ->assertSessionHas('success', '2 reviews approved.');

    expect($a->fresh()->status)->toBe(ReviewStatus::Approved)
        ->and($b->fresh()->status)->toBe(ReviewStatus::Approved)
        // No history row for a non-change.
        ->and(Activity::query()->where('subject_id', $already->id)->where('subject_type', $already->getMorphClass())->exists())->toBeFalse();
});

it('shows an approved review on the storefront and hides a rejected one', function () {
    $moderator = rmEmployee('Vice Chairman');
    $review = rmReview(['comment' => 'Visible after moderation']);
    $product = $review->product;

    $storefront = fn () => $this->get(route('product.show', ['slug' => $product->slug, 'sku' => $product->sku]));
    $storefront()->assertDontSee('Visible after moderation');

    $this->actingAs($moderator, 'employee')->post(route('admin.reviews.approve', $review));
    $storefront()->assertSee('Visible after moderation');

    $this->actingAs($moderator, 'employee')->post(route('admin.reviews.reject', $review));
    $storefront()->assertDontSee('Visible after moderation');
});

it('keeps the whole screen behind reviews.moderate', function () {
    $checker = rmEmployee('Checking');
    $review = rmReview();

    $this->actingAs($checker, 'employee')->get(route('admin.reviews.index'))->assertForbidden();
    $this->actingAs($checker, 'employee')->get(route('admin.reviews.show', $review))->assertForbidden();
    $this->actingAs($checker, 'employee')->post(route('admin.reviews.approve', $review))->assertForbidden();

    expect($review->fresh()->status)->toBe(ReviewStatus::Pending);
});

it('links the new-review notification to the review on the moderation screen', function () {
    $moderator = rmEmployee('Vice Chairman');
    $review = rmReview();

    $notification = $moderator->notifications()->latest()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['route'])->toBe('admin.reviews.show')
        ->and($notification->data['route_params'])->toBe(['review' => $review->id]);
});

it('pages the queue on the server and keeps the active filters in every page link', function () {
    $moderator = rmEmployee('Vice Chairman');
    $product = rmReview(['rating' => 5])->product;
    foreach (range(1, 24) as $i) {
        rmReview(['rating' => 5, 'product' => $product]);
    }
    rmReview(['rating' => 1]);

    $this->actingAs($moderator, 'employee')
        ->get(route('admin.reviews.index', ['rating' => 5, 'search' => 'fit']))
        ->assertInertia(fn ($page) => $page
            // 25 five-star matches, 20 per page — the one-star review is
            // filtered out in SQL, before paging.
            ->where('reviews.total', 25)
            ->where('reviews.from', 1)
            ->where('reviews.to', 20)
            ->where('reviews.last_page', 2)
            ->count('reviews.data', 20)
            ->where('reviews.links', fn ($links) => collect($links)
                ->pluck('url')
                ->filter()
                ->every(fn ($url) => str_contains($url, 'rating=5') && str_contains($url, 'search=fit')))
            ->etc());
});
