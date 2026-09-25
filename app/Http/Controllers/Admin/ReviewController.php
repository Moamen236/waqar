<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reviews\ModerateReviewAction;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * /admin/reviews — the moderation queue for customer product reviews.
 *
 * Customers submit from the product page (Store\ProductController::review)
 * and every review lands Pending; the storefront only ever reads Approved
 * ones. This screen is where a moderator reads them and decides.
 *
 * One permission for the lot: `reviews.moderate` (seeded, Vice Chairman
 * by default). There is nothing on this screen worth showing someone who
 * can't act on it.
 */
class ReviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('permission:reviews.moderate')];
    }

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            // Pending by default: this is a work queue, and what needs a
            // decision is what someone opening it came for.
            'status' => ['nullable', Rule::in(['all', ...array_column(ReviewStatus::cases(), 'value')])],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'verified' => ['nullable', Rule::in(['1', '0'])],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $status = $filters['status'] ?? ReviewStatus::Pending->value;

        $reviews = $this->filtered($filters)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->with(['product:id,name,slug', 'customer:id,name,phone', 'media'])
            // Oldest first while pending — first come, first moderated.
            // Newest first for everything already decided.
            ->orderBy('id', $status === ReviewStatus::Pending->value ? 'asc' : 'desc')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Review $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'comment' => $review->comment,
                'status' => $review->status->value,
                'verified' => $review->order_item_id !== null,
                'images' => $review->getMedia('review_images')->count(),
                // Null despite the non-null relation type: a soft-deleted
                // product drops out of the relation, and its reviews stay.
                'product' => self::idAndName($review->getRelation('product')),
                'customer' => self::idAndName($review->getRelation('customer')),
                'created_at' => $review->created_at?->toIso8601String(),
            ]);

        // The tab badges count within the other filters, so "Pending (3)"
        // means three for the product/rating being looked at.
        $counts = $this->filtered($filters)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Reviews/Index', [
            'reviews' => $reviews,
            'counts' => [
                'pending' => (int) ($counts[ReviewStatus::Pending->value] ?? 0),
                'approved' => (int) ($counts[ReviewStatus::Approved->value] ?? 0),
                'rejected' => (int) ($counts[ReviewStatus::Rejected->value] ?? 0),
            ],
            'filters' => [
                'status' => $status,
                'rating' => isset($filters['rating']) ? (int) $filters['rating'] : null,
                'verified' => $filters['verified'] ?? null,
                'product_id' => isset($filters['product_id']) ? (int) $filters['product_id'] : null,
                'search' => $filters['search'] ?? '',
            ],
            // Named so the screen can say which product it's narrowed to.
            'product' => isset($filters['product_id'])
                ? Product::query()->whereKey($filters['product_id'])->first(['id', 'name'])
                : null,
        ]);
    }

    public function show(Review $review): Response
    {
        $review->load([
            'product.media',
            'customer',
            'orderItem.order:id,order_number,status,created_at',
            'media',
        ]);

        $history = Activity::query()
            ->where('subject_type', $review->getMorphClass())
            ->where('subject_id', $review->id)
            ->with('causer')
            ->latest('id')
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'event' => $activity->event,
                'from' => $activity->properties['old']['status'] ?? null,
                'to' => $activity->properties['attributes']['status'] ?? null,
                'note' => $activity->properties['note'] ?? null,
                'by' => $activity->causer?->getAttribute('full_name') ?? $activity->causer?->getAttribute('name'),
                'at' => $activity->created_at?->toIso8601String(),
            ]);

        // Nullable in practice (a soft-deleted product or customer drops
        // out of the relation), whatever the model's relation types say.
        /** @var Customer|null $customer */
        $customer = $review->getRelation('customer');
        /** @var Product|null $product */
        $product = $review->getRelation('product');
        /** @var Order|null $order */
        $order = $review->orderItem?->order;

        return Inertia::render('Reviews/Show', [
            'review' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'comment' => $review->comment,
                'status' => $review->status->value,
                'created_at' => $review->created_at?->toIso8601String(),
                'images' => $review->getMedia('review_images')
                    ->map(fn (Media $media) => ['id' => $media->id, 'url' => $media->getUrl()])
                    ->values(),
            ],
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'slug' => $product->slug,
                'image' => $product->getFirstMediaUrl('product_images') ?: null,
                // Live figures, so the moderator sees what an approval
                // would change on the product page.
                'approved_count' => $product->reviews()->where('status', ReviewStatus::Approved)->count(),
                'approved_average' => round((float) $product->reviews()->where('status', ReviewStatus::Approved)->avg('rating'), 1),
            ] : null,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'is_guest' => (bool) $customer->is_guest,
                // A reviewer with a trail of rejected reviews reads
                // differently from one on their first.
                'reviews' => Review::query()
                    ->where('customer_id', $customer->id)
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'created_at' => $order->created_at?->toIso8601String(),
            ] : null,
            'history' => $history,
        ]);
    }

    public function approve(Request $request, Review $review, ModerateReviewAction $action): RedirectResponse
    {
        return $this->decide($request, $review, ReviewStatus::Approved, $action);
    }

    public function reject(Request $request, Review $review, ModerateReviewAction $action): RedirectResponse
    {
        return $this->decide($request, $review, ReviewStatus::Rejected, $action);
    }

    /**
     * Several at once from the queue — the common case is a morning's
     * worth of short, fine reviews. Same Action per review, so each gets
     * its own history entry.
     */
    public function bulk(Request $request, ModerateReviewAction $action): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:reviews,id'],
            'decision' => ['required', Rule::in([ReviewStatus::Approved->value, ReviewStatus::Rejected->value])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $decision = ReviewStatus::from($data['decision']);
        $changed = 0;

        foreach (Review::query()->whereIn('id', $data['ids'])->get() as $review) {
            $changed += (int) $action->execute($review, $decision, $request->user('employee'), $data['note'] ?? null);
        }

        return back()->with('success', $decision === ReviewStatus::Approved
            ? __(':count reviews approved.', ['count' => $changed])
            : __(':count reviews rejected.', ['count' => $changed]));
    }

    private function decide(Request $request, Review $review, ReviewStatus $decision, ModerateReviewAction $action): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->execute($review, $decision, $request->user('employee'), $data['note'] ?? null);

        return back()->with('success', $decision === ReviewStatus::Approved
            ? __('Review approved — it now shows on the product page.')
            : __('Review rejected — it is hidden from the product page.'));
    }

    /**
     * @return array{id: int, name: mixed}|null
     */
    private static function idAndName(mixed $model): ?array
    {
        return $model instanceof Model ? ['id' => (int) $model->getKey(), 'name' => $model->getAttribute('name')] : null;
    }

    /**
     * The filters shared by the list and its tab counts — everything but
     * the status itself.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Review>
     */
    private function filtered(array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return Review::query()
            ->when(isset($filters['rating']), fn (Builder $query) => $query->where('rating', (int) $filters['rating']))
            ->when(isset($filters['verified']), fn (Builder $query) => $filters['verified'] === '1'
                ? $query->whereNotNull('order_item_id')
                : $query->whereNull('order_item_id'))
            ->when(isset($filters['product_id']), fn (Builder $query) => $query->where('product_id', (int) $filters['product_id']))
            ->when($search !== '', function (Builder $query) use ($search) {
                $term = "%{$search}%";

                $query->where(fn (Builder $inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('comment', 'like', $term)
                    ->orWhereHas('customer', fn (Builder $customer) => $customer
                        ->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term))
                    // Translatable JSON: match either language.
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('name->en', 'like', $term)
                        ->orWhere('name->ar', 'like', $term)));
            });
    }
}
