import { Link } from '@inertiajs/react';
import BsPagination from 'react-bootstrap/Pagination';
import type { PaginatedData } from '../types';
import { useTranslation } from '../lib/useTranslation';

/**
 * react-bootstrap's Pagination already renders the exact
 * `ul.pagination > li.page-item > a.page-link` markup Larkon's own list
 * pages use (product-list.html, customer-list.html) — Larkon re-themes
 * standard Bootstrap pagination via CSS variables rather than renaming
 * its classes, so no custom markup is needed here.
 *
 * The links are Laravel's own (`paginate()->withQueryString()` on the
 * controller), so each already carries the page's filters, search and
 * sort — nothing to rebuild here. Rendered as Inertia Links: a page
 * change is an Inertia visit, not a full reload.
 */
export default function Pagination<T>({ data }: { data: PaginatedData<T> }) {
    if (data.last_page <= 1) return null;

    return (
        <BsPagination className="mb-0 flex-wrap" size="sm">
            {data.links.map((link, i) => (
                <BsPagination.Item
                    key={i}
                    active={link.active}
                    disabled={!link.url}
                    as={link.url ? Link : 'span'}
                    href={link.url ?? undefined}
                >
                    {/* Laravel's labels carry &laquo;/&raquo; entities. */}
                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                </BsPagination.Item>
            ))}
        </BsPagination>
    );
}

/**
 * "Showing 21–40 of 312" — where this page sits in the whole result. The
 * numbers come from the paginator itself, so they already reflect the
 * active filters.
 */
export function PaginationSummary<T>({ data }: { data: PaginatedData<T> }) {
    const { t } = useTranslation();

    if (data.total === 0 || data.from == null || data.to == null) return null;

    return (
        <div className="text-muted fs-13">
            {t('admin.paginationSummary', { from: data.from, to: data.to, total: data.total })}
            {data.last_page > 1 && (
                <span className="ms-2">
                    · {t('admin.paginationPage', { page: data.current_page, pages: data.last_page })}
                </span>
            )}
        </div>
    );
}

/**
 * The pagination strip in its Larkon placement: a `card-footer border-top`
 * at the bottom of the list card, with the summary on one side and the
 * page links on the other (stacked on narrow screens).
 *
 * Shown whenever the list has rows — even a single page gets its "Showing
 * 1–7 of 7". Hidden when empty: the table's own empty state says that
 * better than "0 of 0" would.
 */
export function PaginationFooter<T>({ data }: { data: PaginatedData<T> }) {
    if (data.total === 0) return null;

    return (
        <div className="card-footer border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
            <PaginationSummary data={data} />
            <Pagination data={data} />
        </div>
    );
}
