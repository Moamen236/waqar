import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import StarRating from '../../Components/StarRating';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { useTranslation } from '../../lib/useTranslation';
import type { PaginatedData } from '../../types';

type Status = 'pending' | 'approved' | 'rejected';

interface ReviewRow {
    id: number;
    rating: number;
    title: string | null;
    comment: string | null;
    status: Status;
    verified: boolean;
    images: number;
    product: { id: number; name: string } | null;
    customer: { id: number; name: string } | null;
    created_at: string;
}

interface Filters {
    status: Status | 'all';
    rating: number | null;
    verified: '1' | '0' | null;
    product_id: number | null;
    search: string;
}

const TABS: (Status | 'all')[] = ['pending', 'approved', 'rejected', 'all'];

/**
 * The moderation queue. Pending first — that's the work — with the
 * decided reviews one tab away for reversing a call. Rows can be approved
 * or rejected where they stand, or ticked and decided together.
 */
export default function ReviewsIndex({
    reviews,
    counts,
    filters,
    product,
}: {
    reviews: PaginatedData<ReviewRow>;
    counts: Record<Status, number>;
    filters: Filters;
    product: { id: number; name: string } | null;
}) {
    const { t, dateTime } = useTranslation();
    const [selected, setSelected] = useState<number[]>([]);

    const total = counts.pending + counts.approved + counts.rejected;
    const allSelected = reviews.data.length > 0 && selected.length === reviews.data.length;

    function visit(next: Partial<Filters>) {
        setSelected([]);
        const merged = { ...filters, ...next };
        router.get(
            route('admin.reviews.index'),
            // Empty values left out, so the URL only carries what's set.
            Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== null && value !== '')),
            { preserveState: true, replace: true },
        );
    }

    const toggle = (id: number) =>
        setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

    async function decideOne(row: ReviewRow, decision: 'approved' | 'rejected') {
        // Approving is the everyday case; rejecting hides a customer's
        // words from the shop, so it asks first.
        if (decision === 'rejected') {
            const confirmed = await confirmAction({
                title: t('admin.rejectThisReviewQ'),
                text: t('admin.rejectReviewHint'),
                confirmText: t('admin.reject'),
                danger: true,
            });
            if (!confirmed) return;
        }

        router.post(
            route(decision === 'approved' ? 'admin.reviews.approve' : 'admin.reviews.reject', row.id),
            {},
            { preserveScroll: true },
        );
    }

    async function decideSelected(decision: 'approved' | 'rejected') {
        const confirmed = await confirmAction({
            title:
                decision === 'approved'
                    ? t('admin.approveNReviewsQ', { count: selected.length })
                    : t('admin.rejectNReviewsQ', { count: selected.length }),
            text: decision === 'approved' ? t('admin.approveReviewHint') : t('admin.rejectReviewHint'),
            confirmText: decision === 'approved' ? t('admin.approve') : t('admin.reject'),
            danger: decision === 'rejected',
        });
        if (!confirmed) return;

        router.post(
            route('admin.reviews.bulk'),
            { ids: selected, decision },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    }

    return (
        <AdminLayout title={t('admin.reviews')}>
            <Head title={t('admin.reviews')} />

            <ul className="nav nav-pills mb-3">
                {TABS.map((tab) => (
                    <li className="nav-item" key={tab}>
                        <button
                            type="button"
                            className={`nav-link ${filters.status === tab ? 'active' : ''}`}
                            onClick={() => visit({ status: tab })}
                        >
                            {t(`admin.reviewTab_${tab}`)}
                            <span className="badge bg-light text-dark ms-2">{tab === 'all' ? total : counts[tab]}</span>
                        </button>
                    </li>
                ))}
            </ul>

            <div className="card">
                <div className="card-header d-flex flex-wrap align-items-center gap-2">
                    <h4 className="card-title flex-grow-1 mb-0">
                        {product ? t('admin.reviewsOfProduct', { product: product.name }) : t('admin.customerReviews')}
                    </h4>
                    {product && (
                        <button
                            type="button"
                            className="btn btn-sm btn-soft-secondary"
                            onClick={() => visit({ product_id: null })}
                        >
                            {t('admin.allProducts')}
                        </button>
                    )}
                    <SearchFilter
                        value={filters.search}
                        placeholder={t('admin.searchReviews')}
                        onSubmit={(search) => visit({ search })}
                    >
                        <select
                            className="form-select form-select-sm"
                            style={{ width: 140 }}
                            aria-label={t('admin.rating')}
                            value={filters.rating ?? ''}
                            onChange={(event) =>
                                visit({ rating: event.target.value ? Number(event.target.value) : null })
                            }
                        >
                            <option value="">{t('admin.anyRating')}</option>
                            {[5, 4, 3, 2, 1].map((stars) => (
                                <option key={stars} value={stars}>
                                    {t('admin.nStars', { count: stars })}
                                </option>
                            ))}
                        </select>
                        <select
                            className="form-select form-select-sm"
                            style={{ width: 170 }}
                            aria-label={t('admin.verifiedPurchase')}
                            value={filters.verified ?? ''}
                            onChange={(event) =>
                                visit({ verified: (event.target.value || null) as Filters['verified'] })
                            }
                        >
                            <option value="">{t('admin.anyBuyer')}</option>
                            <option value="1">{t('admin.verifiedPurchase')}</option>
                            <option value="0">{t('admin.notVerified')}</option>
                        </select>
                    </SearchFilter>
                </div>

                {selected.length > 0 && (
                    <div className="bg-light-subtle border-top border-bottom p-3 d-flex flex-wrap align-items-center gap-2">
                        <div className="fw-semibold flex-grow-1">
                            {t('admin.nReviewsSelected', { count: selected.length })}
                        </div>
                        <button
                            type="button"
                            className="btn btn-sm btn-success"
                            onClick={() => decideSelected('approved')}
                        >
                            <i className="bx bx-check me-1" />
                            {t('admin.approve')}
                        </button>
                        <button
                            type="button"
                            className="btn btn-sm btn-danger"
                            onClick={() => decideSelected('rejected')}
                        >
                            <i className="bx bx-x me-1" />
                            {t('admin.reject')}
                        </button>
                        <button type="button" className="btn btn-sm btn-soft-secondary" onClick={() => setSelected([])}>
                            {t('admin.clearSelection')}
                        </button>
                    </div>
                )}

                <div className="table-responsive">
                    <table className="table align-middle mb-0 table-hover table-centered">
                        <thead className="bg-light-subtle">
                            <tr>
                                <th style={{ width: 40 }}>
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        aria-label={t('admin.selectAll')}
                                        checked={allSelected}
                                        onChange={(event) =>
                                            setSelected(event.target.checked ? reviews.data.map((row) => row.id) : [])
                                        }
                                    />
                                </th>
                                <th>{t('admin.review')}</th>
                                <th>{t('admin.product')}</th>
                                <th>{t('admin.customer')}</th>
                                <th>{t('admin.status')}</th>
                                <th>{t('admin.submitted')}</th>
                                <th>{t('admin.action')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {reviews.data.map((row) => (
                                <tr key={row.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            aria-label={`#${row.id}`}
                                            checked={selected.includes(row.id)}
                                            onChange={() => toggle(row.id)}
                                        />
                                    </td>
                                    <td style={{ minWidth: 260, maxWidth: 420 }}>
                                        <div className="d-flex align-items-center gap-2 flex-wrap">
                                            <StarRating rating={row.rating} />
                                            {row.verified && (
                                                <span className="badge bg-success-subtle text-success">
                                                    <i className="bx bx-check-shield me-1" />
                                                    {t('admin.verifiedPurchase')}
                                                </span>
                                            )}
                                            {row.images > 0 && (
                                                <span className="badge bg-light text-dark">
                                                    <i className="bx bx-image me-1" />
                                                    {row.images}
                                                </span>
                                            )}
                                        </div>
                                        {row.title && <div className="fw-medium mt-1">{row.title}</div>}
                                        {row.comment ? (
                                            <div className="text-muted fs-13 text-truncate">{row.comment}</div>
                                        ) : (
                                            !row.title && (
                                                <div className="text-muted fs-13 fst-italic">
                                                    {t('admin.ratingOnly')}
                                                </div>
                                            )
                                        )}
                                    </td>
                                    <td>
                                        {row.product ? (
                                            <button
                                                type="button"
                                                className="btn btn-link p-0 text-start text-reset"
                                                title={t('admin.showReviewsOfThisProduct')}
                                                onClick={() => visit({ product_id: row.product!.id })}
                                            >
                                                {row.product.name}
                                            </button>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td>{row.customer?.name ?? '—'}</td>
                                    <td>
                                        <StatusBadge status={row.status} />
                                    </td>
                                    <td className="text-muted fs-13 text-nowrap" dir="ltr">
                                        {dateTime(row.created_at)}
                                    </td>
                                    <td>
                                        <div className="d-flex gap-1">
                                            <Link
                                                href={route('admin.reviews.show', row.id)}
                                                className="btn btn-light btn-sm"
                                                title={t('admin.view')}
                                                aria-label={t('admin.view')}
                                            >
                                                <i className="bx bx-show align-middle fs-18" />
                                            </Link>
                                            {row.status !== 'approved' && (
                                                <button
                                                    type="button"
                                                    className="btn btn-soft-success btn-sm"
                                                    title={t('admin.approve')}
                                                    aria-label={t('admin.approve')}
                                                    onClick={() => decideOne(row, 'approved')}
                                                >
                                                    <i className="bx bx-check align-middle fs-18" />
                                                </button>
                                            )}
                                            {row.status !== 'rejected' && (
                                                <button
                                                    type="button"
                                                    className="btn btn-soft-danger btn-sm"
                                                    title={t('admin.reject')}
                                                    aria-label={t('admin.reject')}
                                                    onClick={() => decideOne(row, 'rejected')}
                                                >
                                                    <i className="bx bx-x align-middle fs-18" />
                                                </button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {reviews.data.length === 0 && (
                                <EmptyRow
                                    colSpan={7}
                                    icon="bx-star"
                                    message={
                                        filters.status === 'pending'
                                            ? t('admin.noReviewsAwaitingModeration')
                                            : t('admin.noReviewsMatch')
                                    }
                                />
                            )}
                        </tbody>
                    </table>
                </div>
                <PaginationFooter data={reviews} />
            </div>
        </AdminLayout>
    );
}
