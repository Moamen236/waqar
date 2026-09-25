<?php

namespace App\Observers;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Services\Notifications\StaffNotifier;
use Illuminate\Support\Facades\DB;

/**
 * A review lands pending and waits for someone to moderate it (Section
 * 23's "New Review"). Vice Chairman owns the catalog surface. The bell
 * opens the review itself on the moderation screen.
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
            'admin.reviews.show',
            ['review' => $review->id],
        ));
    }
}
