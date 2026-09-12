import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ProductCard from './ProductCard';
import type { ProductCardData } from '../types';

/**
 * Anvogue's `modal-search-block`. The template's "feature keywords" are
 * static demo copy; here the modal previews real results as you type,
 * through /search/suggest (plain MySQL LIKE — no Scout, Section 23).
 */
export default function SearchModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [term, setTerm] = useState('');
    // Keyed by the term they were fetched for, so a stale result set is
    // simply not rendered rather than needing a setState() to clear it.
    const [results, setResults] = useState<{ term: string; products: ProductCardData[] }>({
        term: '',
        products: [],
    });
    const query = term.trim();
    const visible = query.length >= 2 && results.term === query ? results.products : [];

    useEffect(() => {
        if (!open || query.length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`${route('search.suggest')}?q=${encodeURIComponent(query)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            })
                .then((response) => response.json())
                .then((data: { products: ProductCardData[] }) => setResults({ term: query, products: data.products }))
                .catch(() => undefined);
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, open]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        onClose();
        router.get(route('search.index'), { q: term });
    };

    return (
        <div className="modal-search-block" onClick={onClose}>
            <div
                className={`modal-search-main md:p-10 p-6 rounded-[32px] ${open ? 'open' : ''}`}
                onClick={(event) => event.stopPropagation()}
            >
                <form className="form-search relative w-full" onSubmit={submit}>
                    <button type="submit" aria-label="Search">
                        <i className="ph ph-magnifying-glass absolute heading5 end-6 top-1/2 -translate-y-1/2 cursor-pointer"></i>
                    </button>
                    <input
                        type="text"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Searching..."
                        className="text-button-lg h-14 rounded-2xl border border-line w-full ps-6 pe-12"
                    />
                </form>
                {visible.length > 0 && (
                    <div className="list-recent mt-8">
                        <div className="heading6">Products</div>
                        <div className="list-product pb-5 hide-product-sold grid xl:grid-cols-4 sm:grid-cols-3 grid-cols-2 md:gap-[30px] gap-4 mt-4">
                            {visible.map((product) => (
                                <ProductCard key={product.id} product={product} />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
