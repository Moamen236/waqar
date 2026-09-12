import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Breadcrumb from '../../Components/Breadcrumb';
import Pagination from '../../Components/Pagination';
import ProductCard from '../../Components/ProductCard';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { Pagination as PaginationData, ProductCardData } from '../../types';

/** Search results — Anvogue's search-result.html. */
export default function SearchIndex({
    term,
    products,
    pagination,
}: {
    term: string;
    products: ProductCardData[];
    pagination: PaginationData;
}) {
    const [value, setValue] = useState(term);

    return (
        <StorefrontLayout>
            <Head title="Search" />
            <Breadcrumb title="Search Result" />

            <div className="shop-product search-result-block lg:py-20 md:py-14 py-10">
                <div className="container">
                    <div className="heading flex flex-col items-center">
                        <div className="heading4 text-center">
                            Found <span className="result-quantity">{pagination.total}</span> results for &quot;
                            <span className="result">{term}</span>&quot;
                        </div>
                        <div className="input-block lg:w-1/2 sm:w-3/5 w-full md:h-[52px] h-[44px] sm:mt-8 mt-5">
                            <form
                                className="form-search w-full h-full relative"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    router.get(route('search.index'), { q: value });
                                }}
                            >
                                <input
                                    type="text"
                                    value={value}
                                    onChange={(event) => setValue(event.target.value)}
                                    placeholder="Search..."
                                    className="caption1 w-full h-full pl-4 md:pr-[150px] pr-32 rounded-xl border border-line"
                                />
                                <button
                                    type="submit"
                                    className="button-main absolute top-1 bottom-1 right-1 flex items-center justify-center"
                                >
                                    search
                                </button>
                            </form>
                        </div>
                    </div>

                    <div className="list-product-block relative md:pt-10 pt-6">
                        <div className="list-product list-product-result hide-product-sold grid lg:grid-cols-4 sm:grid-cols-3 grid-cols-2 sm:gap-[30px] gap-[20px] mt-5">
                            {products.map((product) => (
                                <ProductCard key={product.id} product={product} />
                            ))}
                        </div>
                        {products.length === 0 && (
                            <div className="caption1 text-secondary text-center py-16">
                                Nothing matched that search.
                            </div>
                        )}
                        <Pagination pagination={pagination} />
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
