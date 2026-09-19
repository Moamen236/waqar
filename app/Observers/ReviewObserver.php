<?php

namespace App\Observers;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * A review lands pending and waits for someone to moderate it (Section
 * 23's "New Review"). Vice Chairman owns the catalog surface.
 *
 * No link: `reviews.moderate` is seeded but no moderation screen exists
 * yet, and pointing the bell at a 404 is worse than a row that only
 * informs. Add the route here when that screen ships.
 */
class ReviewObserver
{
    public function __construct(private readonly StaffNotifier $staff) {}

    public function created(Review $review): void
    {
        if ($review->status !== ReviewStatus::Pending) {
            return;
        }

        DB::afterCommit(fn () => $this->staff->toRoles(
            ['Vice Chairman'],
            'review_submitted',
            [
                'product' => (string) $review->product?->name,
                'rating' => $review->rating,
            ],
        ));
    }
}
