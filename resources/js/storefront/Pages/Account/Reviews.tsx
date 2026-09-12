import { Head, Link } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import Rate from '../../Components/Rate';
import StorefrontLayout from '../../Layouts/StorefrontLayout';

interface ReviewRow {
    id: number;
    rating: number;
    title: string | null;
    comment: string | null;
    status: string;
    created_at: string | null;
    product: { slug: string; name: string; image: string | null };
}

/**
 * The Reviews tab — required by the spec, absent from the template
 * (Section 13, Section 20 #16). Shows the customer their own reviews in
 * every moderation state, since a pending review is invisible on the
 * product page and they'd otherwise think it vanished.
 */
export default function AccountReviews({ reviews }: { reviews: ReviewRow[] }) {
    return (
        <StorefrontLayout>
            <Head title="My Reviews" />
            <Breadcrumb title="My Reviews" />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="reviews" />
                        <div className="right list-filter md:w-2/3 w-full ps-2.5">
                            <div className="text-content w-full p-7 border border-line rounded-xl">
                                <h6 className="heading6">Your reviews</h6>
                                {reviews.length === 0 && (
                                    <div className="caption1 text-secondary mt-4">
                                        You haven&apos;t reviewed anything yet.
                                    </div>
                                )}
                                {reviews.map((review) => (
                                    <div key={review.id} className="flex gap-5 py-5 border-b border-line">
                                        <div className="bg-img flex-shrink-0 w-20 aspect-square rounded-lg overflow-hidden bg-surface">
                                            <img
                                                src={
                                                    review.product.image ??
                                                    '/storefront/images/generated/collection.svg'
                                                }
                                                alt={review.product.name}
                                                className="w-full h-full object-cover"
                                            />
                                        </div>
                                        <div className="w-full">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <Link
                                                    href={route('product.show', review.product.slug)}
                                                    className="text-title"
                                                >
                                                    {review.product.name}
                                                </Link>
                                                <span
                                                    className={`tag px-4 py-1.5 rounded-full caption1 font-semibold ${
                                                        review.status === 'approved'
                                                            ? 'bg-success/10 text-success'
                                                            : review.status === 'rejected'
                                                              ? 'bg-red/10 text-red'
                                                              : 'bg-yellow/10 text-yellow'
                                                    }`}
                                                >
                                                    {review.status}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-2 mt-2">
                                                <Rate value={review.rating} />
                                                <span className="caption2 text-secondary">{review.created_at}</span>
                                            </div>
                                            {review.title && <div className="text-button mt-2">{review.title}</div>}
                                            {review.comment && (
                                                <div className="caption1 text-secondary mt-1">{review.comment}</div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
