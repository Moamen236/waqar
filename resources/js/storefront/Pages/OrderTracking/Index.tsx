import { Head, Link, useForm } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StatusTag from '../../Components/StatusTag';
import Timeline from '../../Components/Timeline';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { OrderTimelineData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface TrackedOrder {
    order_number: number;
    status: string;
    placed_at: string | null;
    total: number;
    recipient_name: string;
    items: { name: string; sku: string; quantity: number; subtotal: number }[];
}

/**
 * Public order tracking — Anvogue's order-tracking.html, in scope by
 * Question 8. The template's second field is a meaningless "Billing
 * Email"; here the pair is order number + the email the order was placed
 * with, which is what actually identifies it. Its generic progress bar is
 * replaced by the real five-stage timeline.
 */
export default function OrderTrackingIndex({
    order,
    timeline,
}: {
    order: TrackedOrder | null;
    timeline: OrderTimelineData | null;
}) {
    const form = useForm({ order_number: '', email: '' });
    const { t, price } = useTranslation();

    return (
        <StorefrontLayout>
            <Head title={t('tracking.title')} />
            <Breadcrumb title={t('tracking.title')} />

            <div className="order-tracking md:py-20 py-10">
                <div className="container">
                    <div className="content-main flex gap-y-8 max-md:flex-col">
                        <div className="left md:w-1/2 w-full lg:pe-[60px] md:pe-[40px] md:border-r border-line">
                            <div className="heading4">{t('tracking.title')}</div>
                            <div className="mt-2">{t('tracking.intro')}</div>
                            <form
                                className="md:mt-7 mt-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(route('order-tracking.show'));
                                }}
                            >
                                <div>
                                    <input
                                        className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                        type="text"
                                        placeholder={t('tracking.orderNumberPlaceholder')}
                                        value={form.data.order_number}
                                        onChange={(event) => form.setData('order_number', event.target.value)}
                                        required
                                    />
                                    {form.errors.order_number && (
                                        <div className="caption1 text-red mt-1">{form.errors.order_number}</div>
                                    )}
                                </div>
                                <div className="mt-5">
                                    <input
                                        className="border-line px-4 pt-3 pb-3 w-full rounded-lg"
                                        type="email"
                                        placeholder={t('auth.emailPlaceholder')}
                                        value={form.data.email}
                                        onChange={(event) => form.setData('email', event.target.value)}
                                        required
                                    />
                                    {form.errors.email && (
                                        <div className="caption1 text-red mt-1">{form.errors.email}</div>
                                    )}
                                </div>
                                <div className="block-button md:mt-7 mt-4">
                                    <button type="submit" className="button-main" disabled={form.processing}>
                                        {t('tracking.trackButton')}
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div className="right md:w-1/2 w-full lg:ps-[60px] md:ps-[40px] flex items-center">
                            <div className="text-content">
                                <div className="heading4">{t('tracking.haveAccount')}</div>
                                <div className="mt-2 text-secondary">{t('tracking.haveAccountBody')}</div>
                                <div className="block-button md:mt-7 mt-4">
                                    <Link href={route('login')} className="button-main">
                                        {t('auth.login')}
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>

                    {order !== null && timeline !== null && (
                        <div className="tracking-result border border-line rounded-xl p-7 md:mt-14 mt-10">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div className="heading6">
                                        {t('order.numbered', { number: order.order_number })}
                                    </div>
                                    <div className="caption1 text-secondary mt-1">
                                        {order.recipient_name} · {t('order.placedOn', { date: order.placed_at ?? '' })}
                                    </div>
                                </div>
                                <StatusTag status={order.status} />
                            </div>

                            <div className="mt-8">
                                <Timeline timeline={timeline} />
                            </div>

                            <div className="heading6 mt-10">{t('order.items')}</div>
                            {order.items.map((item) => (
                                <div
                                    key={item.sku}
                                    className="flex items-center justify-between gap-3 py-4 border-b border-line"
                                >
                                    <div>
                                        <div className="text-title">{item.name}</div>
                                        <div className="caption1 text-secondary mt-1">
                                            {item.sku} × {item.quantity}
                                        </div>
                                    </div>
                                    <div className="text-title">{price(item.subtotal)}</div>
                                </div>
                            ))}
                            <div className="flex items-center justify-between pt-5">
                                <strong className="heading6">{t('order.totalCod')}</strong>
                                <strong className="heading6">{price(order.total)}</strong>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </StorefrontLayout>
    );
}
