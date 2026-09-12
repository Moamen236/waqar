import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../Components/Pagination';
import ProductCard from '../../Components/ProductCard';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { Pagination as PaginationData, ProductCardData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Facets {
    categories: { slug: string; name: string; count: number }[];
    colors: { id: number; name: string; hex: string | null }[];
    sizes: { id: number; name: string; hex: string | null }[];
    price: { min: number; max: number };
}

interface Filters {
    category?: string;
    collection?: string;
    color?: (string | number)[];
    size?: (string | number)[];
    price_min?: string | number | null;
    price_max?: string | number | null;
    rating?: string | number | null;
    availability?: string | null;
    sale?: boolean;
    sort?: string | null;
}

/**
 * Product listing — Anvogue's shop-breadcrumb1.html, the single base for
 * the shop, category and collection pages (Section 17).
 *
 * Changes the audit called for, applied here:
 *  - the Brands filter is gone entirely (products have no brands), as
 *    are the stray height/weight filters left over from other Anvogue
 *    demos;
 *  - Rating and Availability filters are added — required by the spec,
 *    absent from the template (Section 20 #15);
 *  - the type/size/colour facets read the real catalog rather than the
 *    template's hard-coded demo values.
 *
 * Filtering is server-side (a query-string Inertia visit) rather than
 * the template's client-side JSON filtering, so results, counts and
 * pagination all agree and a filtered view is a shareable URL.
 */
export default function ShopIndex({
    heading,
    products,
    pagination,
    filters,
    facets,
}: {
    heading: { type: string; slug: string; name: string; description: string | null } | null;
    products: ProductCardData[];
    pagination: PaginationData;
    filters: Filters;
    facets: Facets;
}) {
    const [priceMin, setPriceMin] = useState(String(filters.price_min ?? facets.price.min));
    const [priceMax, setPriceMax] = useState(String(filters.price_max ?? facets.price.max));
    const { t } = useTranslation();

    const selected = (key: 'color' | 'size', id: number) => (filters[key] ?? []).map(Number).includes(id);

    const apply = (patch: Partial<Filters>) => {
        const next: Record<string, string | number | boolean | (string | number)[] | null | undefined> = {
            category: filters.category,
            collection: filters.collection,
            color: filters.color,
            size: filters.size,
            price_min: filters.price_min,
            price_max: filters.price_max,
            rating: filters.rating,
            availability: filters.availability,
            sale: filters.sale ? 1 : undefined,
            sort: filters.sort,
            ...patch,
        };

        // Scope lives in the URL path (/category/{slug}), not the query
        // string — carrying it in both would double-apply it.
        delete next.category;
        delete next.collection;

        Object.keys(next).forEach((key) => {
            const value = next[key];
            if (value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) {
                delete next[key];
            }
        });

        router.get(window.location.pathname, next as Record<string, string>, {
            preserveScroll: true,
            preserveState: false,
        });
    };

    const toggleFacet = (key: 'color' | 'size', id: number) => {
        const current = (filters[key] ?? []).map(Number);
        const next = current.includes(id) ? current.filter((value) => value !== id) : [...current, id];
        apply({ [key]: next } as Partial<Filters>);
    };

    const title = heading?.name ?? t('nav.shop');

    return (
        <StorefrontLayout>
            <Head title={title} />

            <div className="breadcrumb-block style-img">
                <div className="breadcrumb-main bg-linear overflow-hidden">
                    <div className="container lg:pt-[134px] pt-24 pb-10 relative">
                        <div className="main-content w-full h-full flex flex-col items-center justify-center relative z-[1]">
                            <div className="text-content">
                                <div className="heading2 text-center">{title}</div>
                                <div className="link flex items-center justify-center gap-1 caption1 mt-3">
                                    <Link href={route('home')}>{t('common.homepage')}</Link>
                                    <i className="ph ph-caret-right text-sm text-secondary2"></i>
                                    <div className="text-secondary2 capitalize">{title}</div>
                                </div>
                            </div>
                            <div className="filter-type menu-tab flex flex-wrap items-center justify-center gap-y-5 gap-8 lg:mt-[70px] mt-12 overflow-hidden">
                                {facets.categories.slice(0, 6).map((category) => (
                                    <Link
                                        key={category.slug}
                                        href={route('shop.category', category.slug)}
                                        className={`item tab-item text-button-uppercase cursor-pointer has-line-before line-2px ${
                                            heading?.slug === category.slug ? 'active' : ''
                                        }`}
                                    >
                                        {category.name}
                                    </Link>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="shop-product breadcrumb1 lg:py-20 md:py-14 py-10">
                <div className="container">
                    <div className="flex max-md:flex-wrap max-md:flex-col-reverse gap-y-8">
                        <div className="sidebar lg:w-1/4 md:w-1/3 w-full md:pe-12">
                            <div className="filter-type-block pb-8 border-b border-line">
                                <div className="heading6">{t('shop.productsType')}</div>
                                <div className="list-type filter-type menu-tab mt-4">
                                    {facets.categories.map((category) => (
                                        <Link
                                            key={category.slug}
                                            href={route('shop.category', category.slug)}
                                            className="item tab-item flex items-center justify-between cursor-pointer"
                                        >
                                            <div className="type-name text-secondary has-line-before hover:text-black capitalize">
                                                {category.name}
                                            </div>
                                            <div className="text-secondary2 number">{category.count}</div>
                                        </Link>
                                    ))}
                                </div>
                            </div>

                            {facets.sizes.length > 0 && (
                                <div className="filter-size pb-8 border-b border-line mt-8">
                                    <div className="heading6">{t('geo.size')}</div>
                                    <div className="list-size flex items-center flex-wrap gap-3 gap-y-4 mt-4">
                                        {facets.sizes.map((size) => (
                                            <div
                                                key={size.id}
                                                className={`size-item text-button px-4 py-2 flex items-center justify-center rounded-full border border-line cursor-pointer ${
                                                    selected('size', size.id) ? 'active bg-black text-white' : ''
                                                }`}
                                                onClick={() => toggleFacet('size', size.id)}
                                            >
                                                {size.name}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="filter-price pb-8 border-b border-line mt-8">
                                <div className="heading6">{t('shop.priceRange')}</div>
                                <div className="price-block flex items-center justify-between flex-wrap gap-2 mt-4">
                                    <input
                                        type="number"
                                        className="border border-line rounded-lg px-3 py-2 w-[45%]"
                                        value={priceMin}
                                        min={facets.price.min}
                                        max={facets.price.max}
                                        onChange={(event) => setPriceMin(event.target.value)}
                                        aria-label={t('shop.minPrice')}
                                    />
                                    <input
                                        type="number"
                                        className="border border-line rounded-lg px-3 py-2 w-[45%]"
                                        value={priceMax}
                                        min={facets.price.min}
                                        max={facets.price.max}
                                        onChange={(event) => setPriceMax(event.target.value)}
                                        aria-label={t('shop.maxPrice')}
                                    />
                                </div>
                                <button
                                    type="button"
                                    className="button-main w-full text-center mt-4 py-2"
                                    onClick={() => apply({ price_min: priceMin, price_max: priceMax })}
                                >
                                    {t('common.apply')}
                                </button>
                            </div>

                            {facets.colors.length > 0 && (
                                <div className="filter-color pb-8 border-b border-line mt-8">
                                    <div className="heading6">{t('shop.colors')}</div>
                                    <div className="list-color flex items-center flex-wrap gap-3 gap-y-4 mt-4">
                                        {facets.colors.map((color) => (
                                            <div
                                                key={color.id}
                                                className={`color-item px-3 py-[5px] flex items-center justify-center gap-2 rounded-full border border-line cursor-pointer ${
                                                    selected('color', color.id) ? 'active bg-black text-white' : ''
                                                }`}
                                                onClick={() => toggleFacet('color', color.id)}
                                            >
                                                <div
                                                    className="color w-5 h-5 rounded-full border border-line"
                                                    style={{ backgroundColor: color.hex ?? 'transparent' }}
                                                ></div>
                                                <div className="caption1 capitalize">{color.name}</div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Section 20 #15 — required by the spec, absent from the template. */}
                            <div className="filter-rating pb-8 border-b border-line mt-8">
                                <div className="heading6">{t('shop.rating')}</div>
                                <div className="list-rating mt-4">
                                    {[5, 4, 3, 2, 1].map((stars) => (
                                        <div
                                            key={stars}
                                            className={`rating-item flex items-center justify-between cursor-pointer py-1 ${
                                                Number(filters.rating) === stars ? 'font-semibold' : ''
                                            }`}
                                            onClick={() =>
                                                apply({ rating: Number(filters.rating) === stars ? null : stars })
                                            }
                                        >
                                            <div className="flex items-center gap-1">
                                                {[1, 2, 3, 4, 5].map((star) => (
                                                    <i
                                                        key={star}
                                                        className={`ph-fill ph-star text-sm ${
                                                            star <= stars ? 'text-yellow' : 'text-secondary2'
                                                        }`}
                                                    ></i>
                                                ))}
                                            </div>
                                            <div className="caption1 text-secondary">{t('shop.andUp')}</div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="filter-availability pb-8 mt-8">
                                <div className="heading6">{t('shop.availability')}</div>
                                <div className="list-availability mt-4">
                                    {[
                                        { value: 'in_stock', label: t('shop.inStock') },
                                        { value: 'out_of_stock', label: t('shop.outOfStock') },
                                    ].map((option) => (
                                        <div key={option.value} className="flex items-center py-1">
                                            <div className="block-input">
                                                <input
                                                    type="checkbox"
                                                    id={`availability-${option.value}`}
                                                    checked={filters.availability === option.value}
                                                    onChange={() =>
                                                        apply({
                                                            availability:
                                                                filters.availability === option.value
                                                                    ? null
                                                                    : option.value,
                                                        })
                                                    }
                                                />
                                                <i className="ph-fill ph-check-square icon-checkbox text-2xl"></i>
                                            </div>
                                            <label
                                                htmlFor={`availability-${option.value}`}
                                                className="ps-2 cursor-pointer"
                                            >
                                                {option.label}
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="list-product-block style-grid lg:w-3/4 md:w-2/3 w-full md:ps-3">
                            <div className="filter-heading flex items-center justify-between gap-5 flex-wrap">
                                <div className="left flex has-line items-center flex-wrap gap-5">
                                    <div className="check-sale flex items-center gap-2 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            id="filter-sale"
                                            className="border-line"
                                            checked={Boolean(filters.sale)}
                                            onChange={(event) => apply({ sale: event.target.checked })}
                                        />
                                        <label htmlFor="filter-sale" className="caption1 cursor-pointer">
                                            {t('shop.onSaleOnly')}
                                        </label>
                                    </div>
                                    <div className="caption1 text-secondary">
                                        {t('shop.productCount', { count: pagination.total })}
                                    </div>
                                </div>
                                <div className="sort-product right flex items-center gap-3">
                                    <label htmlFor="select-filter" className="caption1 capitalize">
                                        {t('shop.sortBy')}
                                    </label>
                                    <div className="select-block relative">
                                        <select
                                            id="select-filter"
                                            name="select-filter"
                                            className="caption1 py-2 ps-3 md:pe-20 pe-10 rounded-lg border border-line"
                                            value={filters.sort ?? ''}
                                            onChange={(event) => apply({ sort: event.target.value || null })}
                                        >
                                            <option value="">{t('shop.sortNewest')}</option>
                                            <option value="soldQuantityHighToLow">{t('shop.sortBestSelling')}</option>
                                            <option value="discountHighToLow">{t('shop.sortBestDiscount')}</option>
                                            <option value="priceHighToLow">{t('shop.sortPriceDesc')}</option>
                                            <option value="priceLowToHigh">{t('shop.sortPriceAsc')}</option>
                                        </select>
                                        <i className="ph ph-caret-down absolute top-1/2 -translate-y-1/2 md:end-4 end-2"></i>
                                    </div>
                                </div>
                            </div>

                            <div className="list-product hide-product-sold grid lg:grid-cols-3 grid-cols-2 sm:gap-[30px] gap-[20px] mt-7">
                                {products.map((product) => (
                                    <ProductCard key={product.id} product={product} />
                                ))}
                            </div>

                            {products.length === 0 && (
                                <div className="caption1 text-secondary text-center py-16">{t('shop.noMatches')}</div>
                            )}

                            <Pagination pagination={pagination} />
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
