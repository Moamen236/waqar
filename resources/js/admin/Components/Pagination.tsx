import { Link } from '@inertiajs/react';
import BsPagination from 'react-bootstrap/Pagination';
import type { PaginatedData } from '../types';

/**
 * react-bootstrap's Pagination already renders the exact
 * `ul.pagination > li.page-item > a.page-link` markup Larkon's own list
 * pages use (product-list.html, customer-list.html) — Larkon re-themes
 * standard Bootstrap pagination via CSS variables rather than renaming
 * its classes, so no custom markup is needed here. Callers wrap this in
 * `<div className="card-footer border-top">`, matching every Larkon
 * list page's own placement.
 */
export default function Pagination<T>({ data }: { data: PaginatedData<T> }) {
    if (data.last_page <= 1) return null;

    return (
        <BsPagination className="justify-content-end mb-0">
            {data.links.map((link, i) => (
                <BsPagination.Item
                    key={i}
                    active={link.active}
                    disabled={!link.url}
                    as={link.url ? Link : 'span'}
                    href={link.url ?? undefined}
                >
                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                </BsPagination.Item>
            ))}
        </BsPagination>
    );
}
