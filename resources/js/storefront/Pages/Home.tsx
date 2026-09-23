import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import BrandGlyph, { BRAND_MARK } from '../Components/BrandGlyph';
import ProductCard from '../Components/ProductCard';
import StorefrontLayout from '../Layouts/StorefrontLayout';
import type { ProductCardData } from '../types';
import { useTranslation } from '../lib/useTranslation';

/**
 * Homepage, built as one campaign rather than the template's stack of
 * rails:
 *
 *  1. hero — the campaign portrait full bleed, the headline plays on the
 *     name (وقار), and it's the only thing on the storefront that animates
 *     on its own;
 *  2. the pieces — one product section with New in / Best sellers / On
 *     sale tabs (these were a separate "What's new" rail plus a tab block
 *     showing the same products twice), with an editorial photo set into
 *     the grid;
 *  3. the brand band — the mark and what the name means, linking to About;
 *  4. two edits — Best sellers and On sale, photo first, caption below;
 *  5. collections — only those that have an image to show;
 *  6. the service promises.
 *
 * Every list and link the old page had is still reachable. The brand logo
 * rail and newsletter modal stay dropped (no brands, no newsletter module).
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
    const { t } = useTranslation();

    // Each tab's "View all" opens the shop on the same list.
    const tabs = [
        {
            key: 'new arrivals',
            label: t('home.tabNewArrivals'),
            products: newArrivals,
            href: route('shop.index'),
        },
        {
            key: 'best sellers',
            label: t('home.tabBestSellers'),
            products: bestSellers,
            href: route('shop.index', { sort: 'soldQuantityHighToLow' }),
        },
        { key: 'on sale', label: t('home.tabOnSale'), products: onSale, href: route('shop.index', { sale: 1 }) },
    ];
    const [activeKey, setActiveKey] = useState(tabs[0].key);
    const active = tabs.find((tab) => tab.key === activeKey) ?? tabs[0];

    const edits = [
        {
            href: route('shop.index', { sort: 'soldQuantityHighToLow' }),
            image: '/storefront/images/home/duo.jpg',
            position: 'object-center',
            title: t('home.bannerBestSellers'),
            body: t('home.bannerBestSellersBody'),
            alt: t('home.bannerBestSellersAlt'),
        },
        {
            href: route('shop.index', { sale: 1 }),
            image: '/storefront/images/banner/3.png',
            position: 'object-[40%_center]',
            title: t('home.bannerOnSale'),
            body: t('home.bannerOnSaleBody'),
            alt: t('home.bannerOnSaleAlt'),
        },
    ];

    // Collections are managed in admin and may not have artwork yet; a tile
    // without an image would just be a grey box, so only illustrated ones show.
    const shownCollections = collections.filter((collection) => collection.image !== null);

    const benefits = [
        { icon: 'icon-guarantee', title: t('home.benefitCodTitle'), body: t('home.benefitCodBody') },
        { icon: 'icon-delivery-truck', title: t('home.benefitDeliveryTitle'), body: t('home.benefitDeliveryBody') },
        { icon: 'icon-return', title: t('home.benefitReturnsTitle'), body: t('home.benefitReturnsBody') },
        { icon: 'icon-phone-call', title: t('home.benefitServiceTitle'), body: t('home.benefitServiceBody') },
    ];

    const h2 =
        'text-[clamp(2rem,3.6vw,3rem)] font-medium leading-[1.1] tracking-[-0.015em] rtl:font-bold rtl:leading-[1.35] rtl:tracking-normal';

    return (
        <StorefrontLayout headerStyle="transparent">
            <Head title={t('nav.home')} />

            {/* Hero. The photo keeps the model on its right with open cream
             * space on the left; in RTL it's mirrored so the copy sits on the
             * reading-start side. The paper wash only really shows on narrow
             * screens, where the crop puts the copy over the model. */}
            <section className="slider-block style-one relative overflow-hidden bg-paper xl:h-[860px] lg:h-[780px] md:h-[600px] h-[540px] w-full">
                <img
                    src="/storefront/images/banner/2.png"
                    alt=""
                    className="absolute inset-0 w-full h-full object-cover object-[72%_center] md:object-center rtl:-scale-x-100"
                />
                <div className="absolute inset-0 bg-linear-to-r rtl:bg-linear-to-l from-paper via-paper/75 to-transparent lg:via-transparent lg:from-paper/50" />
                <div className="container relative h-full flex items-center">
                    <div className="lg:w-1/2 md:w-[46%] sm:w-3/5 w-[88%]">
                        <BrandGlyph src={BRAND_MARK} className="waqar-rise w-9 h-9 text-steel" />
                        <h1
                            className="waqar-rise md:mt-7 mt-5 text-[clamp(2.75rem,6.2vw,5.75rem)] font-medium leading-[0.98] tracking-[-0.03em] rtl:font-bold rtl:leading-[1.25] rtl:tracking-normal"
                            style={{ animationDelay: '0.12s' }}
                        >
                            {t('home.heroTitle')}
                        </h1>
                        <p
                            className="waqar-rise md:mt-6 mt-4 md:text-lg text-base md:leading-8 leading-7 text-muted max-w-[38ch] rtl:md:leading-9"
                            style={{ animationDelay: '0.26s' }}
                        >
                            {t('home.heroBody')}
                        </p>
                        <Link
                            href={route('shop.index')}
                            className="waqar-rise button-main md:mt-9 mt-6"
                            style={{ animationDelay: '0.4s' }}
                        >
                            {t('home.heroCta')}
                        </Link>
                    </div>
                </div>
            </section>

            {/* The pieces */}
            <section className="lg:pt-28 pt-16">
                <div className="container">
                    <div className="flex flex-wrap items-end justify-between gap-x-10 gap-y-6">
                        <h2 className={h2}>{t('home.piecesTitle')}</h2>
                        <div role="tablist" className="flex items-center md:gap-8 gap-5">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    role="tab"
                                    aria-selected={tab.key === active.key}
                                    onClick={() => setActiveKey(tab.key)}
                                    className={`relative pb-2 md:text-lg duration-300 after:absolute after:inset-x-0 after:bottom-0 after:h-px after:bg-black after:duration-300 ${
                                        tab.key === active.key
                                            ? 'text-black after:opacity-100'
                                            : 'text-muted hover:text-black after:opacity-0'
                                    }`}
                                >
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="list-product hide-product-sold grid xl:grid-cols-4 sm:grid-cols-3 grid-cols-2 md:gap-x-7 gap-x-4 md:gap-y-12 gap-y-8 md:mt-12 mt-8">
                        <figure className="col-span-2 sm:row-span-2 relative overflow-hidden rounded-2xl max-sm:aspect-[4/5]">
                            <img
                                src="/storefront/images/home/arch.webp"
                                alt={t('home.editorialAlt')}
                                className="absolute inset-0 w-full h-full object-cover object-[55%_30%]"
                            />
                            <figcaption className="absolute inset-x-0 bottom-0 md:p-8 p-5 pt-24 bg-linear-to-t from-black/70 to-transparent text-white md:text-xl text-lg leading-snug rtl:leading-relaxed">
                                <span className="block max-w-[34ch]">{t('home.editorialCaption')}</span>
                            </figcaption>
                        </figure>
                        {active.products.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                        {active.products.length === 0 && (
                            <p className="sm:col-span-1 col-span-2 self-center text-muted">{t('home.nothingYet')}</p>
                        )}
                    </div>

                    <div className="md:mt-12 mt-10 flex justify-center">
                        <Link
                            href={active.href}
                            className="text-button border-b border-black pb-1 hover:text-steel hover:border-steel duration-300"
                        >
                            {t('home.viewAll')}
                        </Link>
                    </div>
                </div>
            </section>

            {/* The brand */}
            <section className="lg:mt-32 mt-20 bg-black text-white overflow-hidden">
                <div className="container grid lg:grid-cols-12 lg:gap-10 gap-12 items-center lg:py-28 py-20">
                    <div className="lg:col-span-5 flex max-lg:justify-center">
                        <BrandGlyph
                            src={BRAND_MARK}
                            className="lg:w-[22rem] w-[min(15rem,60vw)] aspect-square text-steel"
                        />
                    </div>
                    <div className="lg:col-span-6 lg:col-start-7">
                        <h2 className="text-[clamp(2rem,4vw,3.5rem)] font-medium leading-[1.08] tracking-[-0.02em] text-green max-w-[16ch] rtl:font-bold rtl:leading-[1.35] rtl:tracking-normal">
                            {t('home.storyTitle')}
                        </h2>
                        <p className="mt-6 text-lg leading-8 text-white/75 max-w-[44ch] rtl:leading-9">
                            {t('home.storyBody')}
                        </p>
                        <Link
                            href={route('pages.about')}
                            className="inline-block mt-9 text-green border-b border-green/40 pb-1 hover:border-green duration-300"
                        >
                            {t('home.storyLink')}
                        </Link>
                    </div>
                </div>
            </section>

            {/* Two edits. The second is set lower on wide screens so the pair
             * reads as a spread rather than a row of equal banners. */}
            <section className="lg:pt-32 pt-20">
                <div className="container grid md:grid-cols-2 lg:gap-10 md:gap-7 gap-12">
                    {edits.map((edit, index) => (
                        <Link
                            key={edit.href}
                            href={edit.href}
                            className={`group block ${index === 1 ? 'md:mt-28' : ''}`}
                        >
                            <div className="overflow-hidden rounded-2xl aspect-[4/5] bg-paper">
                                <img
                                    src={edit.image}
                                    alt={edit.alt}
                                    className={`w-full h-full object-cover ${edit.position} duration-700 group-hover:scale-[1.03] motion-reduce:transition-none motion-reduce:group-hover:scale-100`}
                                />
                            </div>
                            <div className="mt-5 flex items-end justify-between gap-6">
                                <div>
                                    <h3 className="text-2xl font-medium rtl:font-bold">{edit.title}</h3>
                                    <p className="mt-1 text-muted">{edit.body}</p>
                                </div>
                                <span className="flex-shrink-0 text-button border-b border-black pb-1 group-hover:text-steel group-hover:border-steel duration-300">
                                    {t('home.shopNow')}
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            </section>

            {shownCollections.length > 0 && (
                <section className="lg:pt-32 pt-20">
                    <div className="container">
                        <h2 className={h2}>{t('home.exploreCollections')}</h2>
                        <div className="grid lg:grid-cols-4 sm:grid-cols-3 grid-cols-2 md:gap-7 gap-4 md:mt-12 mt-8">
                            {shownCollections.map((collection) => (
                                <Link
                                    key={collection.slug}
                                    href={route('shop.collection', collection.slug)}
                                    className="group block"
                                >
                                    <div className="overflow-hidden rounded-2xl aspect-[3/4] bg-paper">
                                        <img
                                            src={collection.image ?? ''}
                                            alt=""
                                            className="w-full h-full object-cover duration-700 group-hover:scale-[1.03] motion-reduce:transition-none"
                                        />
                                    </div>
                                    <p className="mt-4 text-lg">{collection.name}</p>
                                </Link>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* Promises */}
            <section className="lg:mt-32 mt-20 bg-paper">
                <div className="container grid lg:grid-cols-4 sm:grid-cols-2 gap-x-10 gap-y-10 lg:py-16 py-12">
                    {benefits.map((benefit) => (
                        <div key={benefit.title} className="flex gap-4">
                            <i className={`${benefit.icon} text-4xl text-steel flex-shrink-0`} aria-hidden="true"></i>
                            <div>
                                <h3 className="font-bold">{benefit.title}</h3>
                                <p className="mt-1 text-sm leading-6 text-muted rtl:leading-7">{benefit.body}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </section>
        </StorefrontLayout>
    );
}
