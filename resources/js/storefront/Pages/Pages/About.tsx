import { Head, Link } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/** Anvogue's about.html — a static route, not a CMS page (Section 17). */
export default function About() {
    const { t } = useTranslation();
    return (
        <StorefrontLayout>
            <Head title={t('footer.aboutUs')} />
            <Breadcrumb title={t('footer.aboutUs')} />

            <div className="about md:pt-20 pt-10">
                <div className="about-us-block">
                    <div className="container">
                        <div className="text flex items-center justify-center">
                            <div className="content md:w-5/6 w-full">
                                <div className="heading3 text-center">WAQAR</div>
                                <div className="body1 text-center md:mt-7 mt-5">{t('about.intro')}</div>
                            </div>
                        </div>
                        <div className="list-img grid sm:grid-cols-3 gap-[30px] md:pt-20 pt-10">
                            <div className="bg-img">
                                <img
                                    src="/storefront/images/generated/about-1.svg"
                                    alt=""
                                    className="w-full rounded-3xl"
                                />
                            </div>
                            <div className="bg-img">
                                <img
                                    src="/storefront/images/generated/about-2.svg"
                                    alt=""
                                    className="w-full rounded-3xl"
                                />
                            </div>
                            <div className="bg-img">
                                <img
                                    src="/storefront/images/generated/about-3.svg"
                                    alt=""
                                    className="w-full rounded-3xl"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div className="benefit-block md:py-20 py-10">
                    <div className="container">
                        <div className="list-benefit grid items-start lg:grid-cols-3 grid-cols-1 gap-[30px]">
                            <div className="benefit-item flex flex-col items-center justify-center">
                                <i className="icon-guarantee lg:text-7xl text-5xl"></i>
                                <div className="heading6 text-center mt-5">{t('about.codTitle')}</div>
                                <div className="caption1 text-secondary text-center mt-3">{t('about.codBody')}</div>
                            </div>
                            <div className="benefit-item flex flex-col items-center justify-center">
                                <i className="icon-delivery-truck lg:text-7xl text-5xl"></i>
                                <div className="heading6 text-center mt-5">{t('about.deliveryTitle')}</div>
                                <div className="caption1 text-secondary text-center mt-3">
                                    {t('about.deliveryBody')}
                                </div>
                            </div>
                            <div className="benefit-item flex flex-col items-center justify-center">
                                <i className="icon-return lg:text-7xl text-5xl"></i>
                                <div className="heading6 text-center mt-5">{t('about.returnsTitle')}</div>
                                <div className="caption1 text-secondary text-center mt-3">{t('about.returnsBody')}</div>
                            </div>
                        </div>
                        <div className="text-center md:mt-14 mt-10">
                            <Link href={route('shop.index')} className="button-main">
                                {t('about.shopCollection')}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
