import { router } from '@inertiajs/react';
import type { Pagination as PaginationData } from '../types';
import { useTranslation } from '../lib/useTranslation';

/** Anvogue's `list-pagination` row, driven by Laravel's paginator. */
export default function Pagination({ pagination }: { pagination: PaginationData }) {
    const { t } = useTranslation();

    if (pagination.last_page <= 1) {
        return null;
    }

    const go = (page: number) => {
        const url = new URL(window.location.href);
        url.searchParams.set('page', String(page));
        router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true });
    };

    const pages = Array.from({ length: pagination.last_page }, (_, index) => index + 1);

    return (
        <div className="list-pagination w-full flex items-center justify-center gap-4 mt-10">
            {pagination.current_page > 1 && (
                <button
                    type="button"
                    className="w-10 h-10 flex items-center justify-center border border-line rounded-full"
                    onClick={() => go(pagination.current_page - 1)}
                    aria-label={t('common.previousPage')}
                >
                    <i className="ph ph-caret-left"></i>
                </button>
            )}
            {pages.map((page) => (
                <button
                    key={page}
                    type="button"
                    className={`w-10 h-10 flex items-center justify-center rounded-full border border-line duration-300 ${
                        page === pagination.current_page ? 'bg-black text-white' : 'hover:bg-black hover:text-white'
                    }`}
                    onClick={() => go(page)}
                >
                    {page}
                </button>
            ))}
            {pagination.current_page < pagination.last_page && (
                <button
                    type="button"
                    className="w-10 h-10 flex items-center justify-center border border-line rounded-full"
                    onClick={() => go(pagination.current_page + 1)}
                    aria-label={t('common.nextPage')}
                >
                    <i className="ph ph-caret-right"></i>
                </button>
            )}
        </div>
    );
}
