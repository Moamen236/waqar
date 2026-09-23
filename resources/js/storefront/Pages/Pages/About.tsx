import { Head, Link } from '@inertiajs/react';
import BrandGlyph, { BRAND_MARK, BRAND_WAW } from '../../Components/BrandGlyph';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

const IMAGES = '/storefront/images/about';

/**
 * About — a static route, not a CMS page (Section 17). The template's
 * about.html (centred intro, three stock images, benefit icons) is
 * replaced with an editorial page built from the brand's own campaign
 * photography and mark:
 *
 *  - the opening pairs the headline with an arch-framed photo; the arch
 *    comes from the courtyard architecture in the campaign shots;
 *  - the navy band is the one loud moment: the name وَقار set as a
 *    dictionary entry, then the mark shown as what it is, the letter و
 *    turned four times around a point;
 *  - the promise keeps the old page's practical content (one team, cash
 *    on delivery, per-area delivery, returns) and its shop link.
 *
 * Layout uses logical properties throughout (start/end, ps/pe) so the
 * Arabic RTL page mirrors itself; only the campaign image, which has the
 * logo printed on it, is never flipped.
 */
export default function About() {
    const { t } = useTranslation();

    const principles = [
        { title: t('about.principleEaseTitle'), body: t('about.principleEaseBody') },
        { title: t('about.principleFewerTitle'), body: t('about.principleFewerBody') },
        { title: t('about.principleRootsTitle'), body: t('about.principleRootsBody') },
    ];

    const promises = [
        { icon: 'icon-guarantee', title: t('about.codTitle'), body: t('about.codBody') },
        { icon: 'icon-delivery-truck', title: t('about.deliveryTitle'), body: t('about.deliveryBody') },
        { icon: 'icon-return', title: t('about.returnsTitle'), body: t('about.returnsBody') },
    ];

    // Section heading: one size for every h2 so the hierarchy is carried by
    // the page's two display moments (the h1 and وَقار), not by variety.
    const h2 =
        'text-[clamp(2rem,3.6vw,3rem)] font-medium leading-[1.1] tracking-[-0.015em] rtl:font-bold rtl:leading-[1.35] rtl:tracking-normal';

    return (
        <StorefrontLayout>
            <Head title={t('footer.aboutUs')} />

            {/* Opening */}
            <section className="bg-paper overflow-hidden">
                <div className="container grid lg:grid-cols-12 lg:gap-10 gap-12 lg:items-end lg:pt-24 pt-12">
                    <div className="lg:col-span-6 lg:pb-28">
                        <h1 className="text-[clamp(2.75rem,6vw,5.25rem)] font-medium leading-[1.02] tracking-[-0.025em] max-w-[11ch] rtl:font-bold rtl:leading-[1.3] rtl:tracking-normal rtl:max-w-[13ch]">
                            {t('about.openingTitle')}
                        </h1>
                        <p className="md:mt-10 mt-6 text-lg leading-8 text-muted max-w-[44ch] rtl:leading-9">
                            {t('about.openingBody')}
                        </p>
                    </div>
                    <div className="lg:col-span-5 lg:col-start-8">
                        <img
                            src={`${IMAGES}/corduroy.jpg`}
                            alt=""
                            className="w-full aspect-[4/5] object-cover object-[58%_center] rounded-t-full max-lg:max-w-[440px] max-lg:mx-auto"
                        />
                    </div>
                </div>
            </section>

            {/* The name */}
            <section className="relative overflow-hidden bg-black text-white">
                <BrandGlyph
                    src={BRAND_MARK}
                    className="absolute top-1/2 -translate-y-1/2 -end-40 w-[36rem] aspect-square text-white/[0.04] max-md:hidden"
                />
                <div className="container relative grid lg:grid-cols-12 gap-10 lg:pt-32 pt-20">
                    <div className="lg:col-span-7">
                        <p className="text-green leading-none">
                            <span lang="ar" dir="rtl" className="font-black text-[clamp(6rem,17vw,12.5rem)]">
                                وَقار
                            </span>
                        </p>
                        <p className="mt-6 text-sm text-green/70">{t('about.namePronunciation')}</p>
                        <p className="mt-5 text-[clamp(1.5rem,2.6vw,2.25rem)] leading-snug max-w-[26ch] rtl:leading-[1.6] rtl:max-w-[32ch] text-balance">
                            {t('about.nameDefinition')}
                        </p>
                    </div>
                    <div className="lg:col-span-4 lg:col-start-9 lg:self-end">
                        <p className="text-lg leading-8 text-white/75 rtl:leading-9">{t('about.nameBody')}</p>
                    </div>
                </div>

                <div className="container relative lg:pt-24 pt-16 lg:pb-32 pb-20">
                    <div className="border-t border-green/20 md:pt-14 pt-10 grid md:grid-cols-12 gap-10 items-center">
                        <div className="md:col-span-5 flex items-center gap-6 text-green">
                            <BrandGlyph src={BRAND_WAW} className="w-10 h-14 flex-shrink-0" />
                            <span className="h-px flex-1 bg-green/30" />
                            <BrandGlyph src={BRAND_MARK} className="w-28 h-28 flex-shrink-0" />
                        </div>
                        <div className="md:col-span-6 md:col-start-7">
                            <h2 className="text-xl font-semibold text-green">{t('about.markTitle')}</h2>
                            <p className="mt-3 leading-7 text-white/75 max-w-[52ch] rtl:leading-8">
                                {t('about.markBody')}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            {/* Approach */}
            <section className="bg-white lg:py-32 py-20">
                <div className="container grid lg:grid-cols-12 lg:gap-10 gap-14 items-center">
                    <div className="lg:col-span-6 relative lg:pb-24 pb-16">
                        <img
                            src={`${IMAGES}/courtyard.webp`}
                            alt=""
                            className="w-[82%] aspect-[4/5] object-cover object-[36%_center] rounded-2xl"
                        />
                        <img
                            src={`${IMAGES}/column.webp`}
                            alt=""
                            className="absolute bottom-0 end-0 w-[46%] aspect-[3/4] object-cover object-[27%_center] rounded-t-full border-[10px] border-white"
                        />
                    </div>
                    <div className="lg:col-span-5 lg:col-start-8">
                        <h2 className={h2}>{t('about.approachTitle')}</h2>
                        <dl className="md:mt-10 mt-8 border-t border-rule">
                            {principles.map((principle) => (
                                <div key={principle.title} className="py-7 border-b border-rule">
                                    <dt className="text-xl font-semibold">{principle.title}</dt>
                                    <dd className="mt-2 leading-7 text-muted rtl:leading-8">{principle.body}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            </section>

            {/* Promise */}
            <section className="bg-paper lg:py-28 py-20">
                <div className="container grid lg:grid-cols-12 gap-12">
                    <div className="lg:col-span-4">
                        <h2 className={h2}>{t('about.promiseTitle')}</h2>
                        <p className="mt-5 text-lg leading-8 text-muted max-w-[40ch] rtl:leading-9">
                            {t('about.promiseBody')}
                        </p>
                    </div>
                    <ul className="lg:col-span-7 lg:col-start-6 grid sm:grid-cols-3 gap-10 lg:pt-2">
                        {promises.map((promise) => (
                            <li key={promise.title}>
                                <i className={`${promise.icon} text-5xl`} aria-hidden="true"></i>
                                <h3 className="mt-5 text-lg font-semibold">{promise.title}</h3>
                                <p className="mt-2 text-sm leading-6 text-muted rtl:leading-7">{promise.body}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            </section>

            {/* Closing */}
            <section className="bg-white">
                <div className="container flex max-md:flex-col md:items-end md:justify-between gap-8 lg:py-24 py-16">
                    <p className="text-[clamp(1.75rem,3.2vw,2.75rem)] font-medium leading-[1.15] tracking-[-0.015em] max-w-[18ch] rtl:font-bold rtl:leading-[1.4] rtl:tracking-normal">
                        {t('about.closingTitle')}
                    </p>
                    <Link href={route('shop.index')} className="button-main flex-shrink-0">
                        {t('about.shopCollection')}
                    </Link>
                </div>
                <img
                    src={`${IMAGES}/campaign.jpg`}
                    alt={t('about.campaignAlt')}
                    className="w-full aspect-[16/9] max-h-[90vh] object-cover"
                />
            </section>
        </StorefrontLayout>
    );
}
