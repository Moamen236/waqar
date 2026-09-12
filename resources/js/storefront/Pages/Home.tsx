import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ProductCard from '../Components/ProductCard';
import StorefrontLayout from '../Layouts/StorefrontLayout';
import type { ProductCardData } from '../types';

/**
 * Homepage — Anvogue's index.html, section for section: the hero slider,
 * "What's new", the collection rail, the best-sellers/on-sale/new-arrivals
 * tab block, the two-up banner, the benefit row.
 *
 * Dropped from the template: the brand logo rail (products have no
 * brands — Section 17's resolved conflict, so there is nothing to put in
 * it) and the newsletter modal (no newsletter module exists in either
 * source document).
 */
export default function Home({
    newArrivals,
    bestSellers,
    onSale,
    collections,
}: {
    newArrivals: ProductCardData[];
    bestSellers: ProductCardData[];
    onSale: ProductCardData[];
    collections: { slug: string; name: string; image: string | null }[];
}) {
    const tabs = [
        { key: 'best sellers', products: bestSellers },
        { key: 'on sale', products: onSale },
        { key: 'new arrivals', products: newArrivals },
    ];
    const [activeTab, setActiveTab] = useState(tabs[0].key);
    const activeProducts = tabs.find((tab) => tab.key === activeTab)?.products ?? [];

    return (
        <StorefrontLayout headerStyle="transparent">
            <Head title="Home" />

            <div className="slider-block style-one bg-linear xl:h-[860px] lg:h-[800px] md:h-[580px] sm:h-[500px] h-[350px] max-[420px]:h-[320px] w-full">
                <div className="slider-main h-full w-full">
                    <div className="slider-item h-full w-full relative">
                        <div className="container w-full h-full flex items-center relative">
                            <div className="text-content basis-1/2">
                                <div className="text-sub-display">Cash on delivery, everywhere we ship</div>
                                <div className="text-display md:mt-5 mt-2">Fashion for every occasion</div>
                                <Link href={route('shop.index')} className="button-main md:mt-8 mt-3">
                                    Shop Now
                                </Link>
                            </div>
                            <div className="sub-img absolute sm:w-1/2 w-3/5 2xl:-end-[60px] -end-[16px] bottom-0">
                                <img src="/storefront/images/generated/hero.svg" alt="" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {newArrivals.length > 0 && (
                <div className="what-new-block filter-product-block md:pt-20 pt-10">
                    <div className="container">
                        <div className="heading flex flex-col items-center text-center">
                            <div className="heading3">What&apos;s new</div>
                        </div>
                        <div className="list-product four-product hide-product-sold grid xl:grid-cols-4 sm:grid-cols-3 grid-cols-2 md:gap-[30px] gap-4 md:mt-10 mt-6">
                            {newArrivals.map((product) => (
                                <ProductCard key={product.id} product={product} />
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {collections.length > 0 && (
                <div className="collection-block md:pt-20 pt-10">
                    <div className="container">
                        <div className="heading3 text-center">Explore Collections</div>
                    </div>
                    <div className="list-collection relative md:mt-10 mt-6 sm:px-5 px-4">
                        <div className="grid xl:grid-cols-4 sm:grid-cols-3 grid-cols-2 gap-5">
                            {collections.map((collection) => (
                                <Link
                                    key={collection.slug}
                                    href={route('shop.collection', collection.slug)}
                                    className="collection-item block relative rounded-2xl overflow-hidden cursor-pointer"
                                >
                                    <div className="bg-img">
                                        <img
                                            src={collection.image ?? '/storefront/images/generated/collection.svg'}
                                            alt={collection.name}
                                        />
                                    </div>
                                    <div className="collection-name heading5 text-center sm:bottom-8 bottom-4 lg:w-[200px] md:w-[160px] w-[100px] md:py-3 py-1.5 bg-white rounded-xl duration-500">
                                        {collection.name}
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            <div className="tab-features-block filter-product-block md:pt-20 pt-10">
                <div className="container">
                    <div className="heading flex flex-col items-center text-center">
                        <div className="menu-tab bg-surface rounded-2xl">
                            <div className="menu flex items-center gap-2 p-1">
                                {tabs.map((tab) => (
                                    <div
                                        key={tab.key}
                                        className={`tab-item relative heading5 py-2 px-5 cursor-pointer duration-500 hover:text-black ${
                                            activeTab === tab.key
                                                ? 'active text-black bg-white rounded-full'
                                                : 'text-secondary'
                                        }`}
                                        onClick={() => setActiveTab(tab.key)}
                                    >
                                        {tab.key}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                    <div className="list-product hide-product-sold grid xl:grid-cols-4 sm:grid-cols-3 grid-cols-2 md:gap-[30px] gap-4 md:mt-10 mt-6">
                        {activeProducts.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                        {activeProducts.length === 0 && (
                            <div className="col-span-full caption1 text-secondary text-center py-10">
                                Nothing here yet.
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <div className="banner-block style-one grid sm:grid-cols-2 gap-5 md:pt-20 pt-10">
                <Link
                    href={route('shop.index', { sort: 'soldQuantityHighToLow' })}
                    className="banner-item relative block overflow-hidden duration-500"
                >
                    <div className="banner-img">
                        <img
                            src="/storefront/images/generated/banner-best-sellers.svg"
                            className="duration-1000"
                            alt="Best sellers"
                        />
                    </div>
                    <div className="banner-content absolute top-0 start-0 w-full h-full flex flex-col items-center justify-center">
                        <div className="heading2 text-white">Best Sellers</div>
                        <div className="text-button text-white relative inline-block pb-1 border-b-2 border-white duration-500 mt-2">
                            Shop Now
                        </div>
                    </div>
                </Link>
                <Link
                    href={route('shop.index', { sale: 1 })}
                    className="banner-item relative block overflow-hidden duration-500"
                >
                    <div className="banner-img">
                        <img
                            src="/storefront/images/generated/banner-on-sale.svg"
                            className="duration-1000"
                            alt="On sale"
                        />
                    </div>
                    <div className="banner-content absolute top-0 start-0 w-full h-full flex flex-col items-center justify-center">
                        <div className="heading2 text-white">On Sale</div>
                        <div className="text-button text-white relative inline-block pb-1 border-b-2 border-white duration-500 mt-2">
                            Shop Now
                        </div>
                    </div>
                </Link>
            </div>

            <div className="benefit-block md:pt-20 pt-10">
                <div className="container">
                    <div className="list-benefit grid items-start lg:grid-cols-4 grid-cols-2 gap-[30px]">
                        <div className="benefit-item flex flex-col items-center justify-center">
                            <i className="icon-phone-call lg:text-7xl text-5xl"></i>
                            <div className="heading6 text-center mt-5">Customer service</div>
                            <div className="caption1 text-secondary text-center mt-3">
                                Our team is on hand to help with anything about your order.
                            </div>
                        </div>
                        <div className="benefit-item flex flex-col items-center justify-center">
                            <i className="icon-return lg:text-7xl text-5xl"></i>
                            <div className="heading6 text-center mt-5">Easy returns</div>
                            <div className="caption1 text-secondary text-center mt-3">
                                Not right? Start a return from your account after delivery.
                            </div>
                        </div>
                        <div className="benefit-item flex flex-col items-center justify-center">
                            <i className="icon-guarantee lg:text-7xl text-5xl"></i>
                            <div className="heading6 text-center mt-5">Pay on delivery</div>
                            <div className="caption1 text-secondary text-center mt-3">
                                Cash on delivery only — no card details are ever collected.
                            </div>
                        </div>
                        <div className="benefit-item flex flex-col items-center justify-center">
                            <i className="icon-delivery-truck lg:text-7xl text-5xl"></i>
                            <div className="heading6 text-center mt-5">Nationwide delivery</div>
                            <div className="caption1 text-secondary text-center mt-3">
                                Shipping rates resolved for your exact area at checkout.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
