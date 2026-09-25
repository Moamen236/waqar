<?php

namespace App\Actions\Reviews;

use App\Enums\ReviewStatus;
use App\Models\Employee;
use App\Models\Review;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Approve or reject a customer review (spec Section 24's moderation
 * queue). A review reaches the storefront only once Approved — every
 * storefront read already filters on that — so this is the single switch
 * that publishes or withdraws one.
 *
 * Either decision can be reversed later (an approved review that turns
 * out to be spam can be rejected, a rejected one reinstated). Nothing
 * moves a review back to Pending: that state means "nobody has looked
 * yet", which stops being true the first time a moderator decides.
 *
 * Every decision lands in the `catalog` activity log with who made it and
 * the optional note — that log is the review's moderation history, and
 * the note is where "why rejected" lives without a column for it.
 */
class ModerateReviewAction
{
    /**
     * @return bool Whether anything changed — false when the review was
     *              already in that state and no note was added.
     */
    public function execute(Review $review, ReviewStatus $decision, Employee $moderator, ?string $note = null): bool
    {
        if ($decision === ReviewStatus::Pending) {
            throw new InvalidArgumentException('A review can be approved or rejected, not returned to pending.');
        }

        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return DB::transaction(function () use ($review, $decision, $moderator, $note) {
            /** @var Review $review */
            $review = Review::query()->lockForUpdate()->findOrFail($review->id);
            $from = $review->status;

            if ($from === $decision && $note === null) {
                return false;
            }

            $review->update(['status' => $decision]);

            // Logged by hand (Review doesn't use RecordsActivity) so the
            // entry carries the moderator's note. Same shape as the
            // trait's entries — attributes/old plus a label snapshot — so
            // the activity log screen reads it like any other change.
            activity('catalog')
                ->performedOn($review)
                ->causedBy($moderator)
                ->event($decision === ReviewStatus::Approved ? 'approved' : 'rejected')
                ->withProperties([
                    'attributes' => ['status' => $decision->value],
                    'old' => ['status' => $from->value],
                    'label' => __('Review #:id', ['id' => $review->id]),
                    'note' => $note,
                ])
                ->log($decision === ReviewStatus::Approved ? 'approved' : 'rejected');

            return true;
        });
    }
}
