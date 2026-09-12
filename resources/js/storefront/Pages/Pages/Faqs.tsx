import { Head } from '@inertiajs/react';
import { useState } from 'react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/**
 * Anvogue's faqs.html accordion, with answers written from this system's
 * actual rules rather than the template's lorem copy — COD only, the
 * five-stage order timeline, area-level shipping rates, and the two
 * return moments (refuse at the door, or return after delivery).
 */
/**
 * Content lives as translation keys rather than literals: the answers are
 * this system's real rules (COD only, the five-stage timeline, area-level
 * rates, the two return moments), and both languages must state them
 * identically. Structure here, copy in the catalogs.
 */
const groups = [
    { title: 'faq.orderingTitle', items: ['faq.pay', 'faq.guest', 'faq.cancel'] },
    { title: 'faq.deliveryTitle', items: ['faq.shipping', 'faq.follow'] },
    { title: 'faq.returnsTitle', items: ['faq.refuse', 'faq.afterDelivery'] },
];

export default function Faqs() {
    const { t } = useTranslation();
    const [open, setOpen] = useState<string | null>('faq.orderingTitle-0');

    return (
        <StorefrontLayout>
            <Head title={t('footer.faqs')} />
            <Breadcrumb title={t('footer.faqs')} />

            <div className="faqs-block md:py-20 py-10">
                <div className="container">
                    <div className="flex justify-between max-xl:flex-col gap-y-8">
                        <div className="left xl:w-1/4 w-full">
                            <div className="menu-tab flex flex-col gap-5">
                                {groups.map((group) => (
                                    <div key={group.title} className="heading6">
                                        {t(group.title)}
                                    </div>
                                ))}
                            </div>
                        </div>
                        <div className="right xl:w-3/4 xl:ps-20">
                            {groups.map((group) => (
                                <div key={group.title} className="tab-question mb-10">
                                    <div className="heading5">{t(group.title)}</div>
                                    {group.items.map((item, index) => {
                                        const key = `${group.title}-${index}`;

                                        return (
                                            <div
                                                key={key}
                                                className={`question-item px-7 rounded-[20px] overflow-hidden border border-line cursor-pointer mt-5 ${
                                                    open === key ? 'open' : ''
                                                }`}
                                                onClick={() => setOpen(open === key ? null : key)}
                                            >
                                                <div className="heading flex items-center justify-between gap-6 py-4">
                                                    <div className="heading6">{t(`${item}.q`)}</div>
                                                    <i
                                                        className={`ph-bold ph-caret-down text-xl duration-300 ${
                                                            open === key ? 'rotate-180' : ''
                                                        }`}
                                                    ></i>
                                                </div>
                                                {open === key && (
                                                    <div className="content body1 text-secondary pb-4">
                                                        {t(`${item}.a`)}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
