import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '../../Components/Form/FormField';
import StarRating from '../../Components/StarRating';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { confirmAction } from '../../lib/confirm';
import { useTranslation } from '../../lib/useTranslation';

type Status = 'pending' | 'approved' | 'rejected';

interface HistoryEntry {
    id: number;
    event: string | null;
    from: Status | null;
    to: Status | null;
    note: string | null;
    by: string | null;
    at: string;
}

/**
 * One review, with everything a moderator weighs before deciding: what
 * was written, whether the reviewer actually bought the product, what
 * else they have written, and what the product page shows today. The
 * decision takes an optional note, kept in the review's history — the
 * place to say why a review was turned down.
 */
export default function ReviewShow({
    review,
    product,
    customer,
    order,
    history,
}: {
    review: {
        id: number;
        rating: number;
        title: string | null;
        comment: string | null;
        status: Status;
        created_at: string;
        images: { id: number; url: string }[];
    };
    product: {
        id: number;
        name: string;
        sku: string;
        slug: string;
        image: string | null;
        approved_count: number;
        approved_average: number;
    } | null;
    customer: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        is_guest: boolean;
        reviews: Partial<Record<Status, number>>;
    } | null;
    order: { id: number; order_number: number; status: string; created_at: string } | null;
    history: HistoryEntry[];
}) {
    const { t, dateTime } = useTranslation();
    const { can } = usePermissions();
    const [note, setNote] = useState('');
    const [processing, setProcessing] = useState(false);

    async function decide(decision: 'approved' | 'rejected') {
        const confirmed = await confirmAction({
            title: decision === 'approved' ? t('admin.approveThisReviewQ') : t('admin.rejectThisReviewQ'),
            text: decision === 'approved' ? t('admin.approveReviewHint') : t('admin.rejectReviewHint'),
            confirmText: decision === 'approved' ? t('admin.approve') : t('admin.reject'),
            danger: decision === 'rejected',
        });
        if (!confirmed) return;

        setProcessing(true);
        router.post(
            route(decision === 'approved' ? 'admin.reviews.approve' : 'admin.reviews.reject', review.id),
            { note },
            {
                preserveScroll: true,
                onSuccess: () => setNote(''),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <AdminLayout
            title={t('admin.reviewNumber', { id: review.id })}
            breadcrumbs={[{ label: t('admin.reviews'), href: route('admin.reviews.index') }]}
        >
            <Head title={t('admin.reviewNumber', { id: review.id })} />

            <div className="row">
                <div className="col-xl-8">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-2">
                            <h4 className="card-title mb-0">{t('admin.review')}</h4>
                            <StatusBadge status={review.status} />
                        </div>
                        <div className="card-body">
                            <div className="d-flex align-items-center gap-2 flex-wrap mb-2">
                                <StarRating rating={review.rating} size="fs-20" />
                                <span className="fw-semibold">{review.rating} / 5</span>
                                {order && (
                                    <span className="badge bg-success-subtle text-success">
                                        <i className="bx bx-check-shield me-1" />
                                        {t('admin.verifiedPurchase')}
                                    </span>
                                )}
                            </div>
                            {review.title && <h5 className="mb-2">{review.title}</h5>}
                            {review.comment ? (
                                // pre-line keeps the customer's own paragraphs.
                                <p className="mb-0" style={{ whiteSpace: 'pre-line' }}>
                                    {review.comment}
                                </p>
                            ) : (
                                <p className="text-muted fst-italic mb-0">{t('admin.ratingOnly')}</p>
                            )}
                            <p className="text-muted fs-13 mt-3 mb-0">
                                {t('admin.submittedOn')} <span dir="ltr">{dateTime(review.created_at)}</span>
                            </p>

                            {review.images.length > 0 && (
                                <div className="d-flex flex-wrap gap-2 mt-3">
                                    {review.images.map((image) => (
                                        <a key={image.id} href={image.url} target="_blank" rel="noreferrer">
                                            <img
                                                src={image.url}
                                                alt={t('admin.reviewPhoto')}
                                                className="rounded border"
                                                style={{ width: 110, height: 110, objectFit: 'cover' }}
                                            />
                                        </a>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.moderationHistory')}</h4>
                        </div>
                        <div className="card-body">
                            <ul className="list-unstyled mb-0">
                                {history.map((entry) => (
                                    <li key={entry.id} className="d-flex gap-2 mb-3">
                                        <i
                                            className={`bx fs-20 ${
                                                entry.to === 'approved'
                                                    ? 'bx-check-circle text-success'
                                                    : 'bx-x-circle text-danger'
                                            }`}
                                            aria-hidden="true"
                                        />
                                        <div>
                                            <div>
                                                {entry.to === 'approved'
                                                    ? t('admin.reviewApprovedBy')
                                                    : t('admin.reviewRejectedBy')}{' '}
                                                <span className="fw-medium">{entry.by ?? t('admin.system')}</span>
                                                {entry.from && entry.from !== entry.to && (
                                                    <span className="text-muted fs-13">
                                                        {' '}
                                                        ({t(`admin.reviewTab_${entry.from}`)} →{' '}
                                                        {t(`admin.reviewTab_${entry.to}`)})
                                                    </span>
                                                )}
                                            </div>
                                            {entry.note && (
                                                <div className="bg-light-subtle border rounded px-2 py-1 mt-1 fs-13">
                                                    {entry.note}
                                                </div>
                                            )}
                                            <div className="text-muted fs-13" dir="ltr">
                                                {dateTime(entry.at)}
                                            </div>
                                        </div>
                                    </li>
                                ))}
                                {/* The first step of every review, whatever came after. */}
                                <li className="d-flex gap-2">
                                    <i className="bx bx-message-square-edit fs-20 text-muted" aria-hidden="true" />
                                    <div>
                                        <div>
                                            {t('admin.reviewSubmittedBy')}{' '}
                                            <span className="fw-medium">{customer?.name ?? '—'}</span>
                                        </div>
                                        <div className="text-muted fs-13" dir="ltr">
                                            {dateTime(review.created_at)}
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div className="col-xl-4">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.decision')}</h4>
                        </div>
                        <div className="card-body">
                            <p className="text-muted fs-13">
                                {review.status === 'pending'
                                    ? t('admin.reviewPendingHint')
                                    : review.status === 'approved'
                                      ? t('admin.reviewApprovedHint')
                                      : t('admin.reviewRejectedHint')}
                            </p>
                            <FormField name="note" label={t('admin.moderationNoteOptional')}>
                                <textarea
                                    className="form-control"
                                    rows={3}
                                    maxLength={1000}
                                    placeholder={t('admin.moderationNotePlaceholder')}
                                    value={note}
                                    onChange={(event) => setNote(event.target.value)}
                                />
                            </FormField>
                            <div className="d-grid gap-2">
                                {review.status !== 'approved' && (
                                    <button
                                        type="button"
                                        className="btn btn-success"
                                        disabled={processing}
                                        onClick={() => decide('approved')}
                                    >
                                        <i className="bx bx-check me-1" />
                                        {t('admin.approveAndPublish')}
                                    </button>
                                )}
                                {review.status !== 'rejected' && (
                                    <button
                                        type="button"
                                        className="btn btn-outline-danger"
                                        disabled={processing}
                                        onClick={() => decide('rejected')}
                                    >
                                        <i className="bx bx-x me-1" />
                                        {review.status === 'approved'
                                            ? t('admin.unpublishAndReject')
                                            : t('admin.reject')}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>

                    {product && (
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.product')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="d-flex gap-2 align-items-center mb-3">
                                    {product.image ? (
                                        <img
                                            src={product.image}
                                            alt=""
                                            className="rounded border"
                                            style={{ width: 56, height: 56, objectFit: 'cover' }}
                                        />
                                    ) : (
                                        <div className="avatar-md bg-light rounded d-flex align-items-center justify-content-center">
                                            <i className="bx bx-image fs-24 text-muted" />
                                        </div>
                                    )}
                                    <div>
                                        <div className="fw-medium">{product.name}</div>
                                        <div className="text-muted fs-13" dir="ltr">
                                            {product.sku}
                                        </div>
                                    </div>
                                </div>
                                <div className="d-flex align-items-center gap-2 fs-13 mb-3">
                                    <StarRating rating={product.approved_average} />
                                    <span className="text-muted">
                                        {t('admin.publishedRating', {
                                            average: product.approved_average,
                                            count: product.approved_count,
                                        })}
                                    </span>
                                </div>
                                <div className="d-flex gap-2 flex-wrap">
                                    {can('products.view') && (
                                        <Link
                                            href={route('admin.products.show', product.id)}
                                            className="btn btn-sm btn-soft-primary"
                                        >
                                            {t('admin.viewProduct')}
                                        </Link>
                                    )}
                                    <Link
                                        href={route('admin.reviews.index', { product_id: product.id, status: 'all' })}
                                        className="btn btn-sm btn-soft-secondary"
                                    >
                                        {t('admin.allReviewsOfThisProduct')}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    )}

                    {customer && (
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.customer')}</h4>
                            </div>
                            <div className="card-body">
                                <div className="fw-medium">
                                    {customer.name}
                                    {customer.is_guest && (
                                        <span className="badge bg-light text-dark ms-2">{t('admin.guest')}</span>
                                    )}
                                </div>
                                {customer.email && <div className="text-muted fs-13">{customer.email}</div>}
                                {customer.phone && (
                                    <div className="text-muted fs-13" dir="ltr">
                                        {customer.phone}
                                    </div>
                                )}
                                <div className="d-flex flex-wrap gap-2 mt-3 fs-13">
                                    {(['approved', 'pending', 'rejected'] as const).map((status) => (
                                        <span key={status} className="badge bg-light text-dark">
                                            {t(`admin.reviewTab_${status}`)}: {customer.reviews[status] ?? 0}
                                        </span>
                                    ))}
                                </div>
                                {can('customers.update') && (
                                    <Link
                                        href={route('admin.customers.edit', customer.id)}
                                        className="btn btn-sm btn-soft-primary mt-3"
                                    >
                                        {t('admin.viewCustomer')}
                                    </Link>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.purchase')}</h4>
                        </div>
                        <div className="card-body">
                            {order ? (
                                <>
                                    <p className="mb-2 fs-13 text-muted">{t('admin.verifiedPurchaseHint')}</p>
                                    <div className="d-flex align-items-center gap-2 flex-wrap">
                                        {can('orders.view') ? (
                                            <Link href={route('admin.orders.show', order.id)} className="fw-medium">
                                                #{order.order_number}
                                            </Link>
                                        ) : (
                                            <span className="fw-medium">#{order.order_number}</span>
                                        )}
                                        <StatusBadge status={order.status} />
                                    </div>
                                    <div className="text-muted fs-13 mt-1" dir="ltr">
                                        {dateTime(order.created_at)}
                                    </div>
                                </>
                            ) : (
                                <p className="mb-0 fs-13 text-muted">{t('admin.notVerifiedHint')}</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
