import { Head, Link } from '@inertiajs/react';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/** Anvogue's page-not-found.html, reusable as-is (Section 17). */
export default function NotFound() {
    const { t } = useTranslation();

    return (
        <StorefrontLayout>
            <Head title={t('errors.notFoundTitle')} />

            <div className="page-not-found md:py-20 py-10 bg-linear">
                <div className="container">
                    <div className="flex items-center justify-between max-sm:flex-col gap-y-8">
                        <img src="/storefront/images/generated/not-found.svg" alt="" className="sm:w-1/2 w-3/4" />
                        <div className="text-content sm:w-1/2 w-full flex items-center justify-center sm:ps-10">
                            <div>
                                <div className="lg:text-[140px] md:text-[80px] text-[42px] lg:leading-[152px] md:leading-[92px] leading-[52px] font-semibold">
                                    404
                                </div>
                                <div className="heading2 mt-4">{t('errors.notFoundTitle')}</div>
                                <div className="body1 text-secondary mt-4 pb-4">
                                    {t('errors.notFoundBody')}
                                    <br className="max-xl:hidden" />
                                    {t('errors.notFoundHint')}
                                </div>
                                <Link className="flex items-center gap-3" href={route('home')}>
                                    <i className="ph ph-arrow-left"></i>
                                    <div className="text-button">{t('errors.backHome')}</div>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
