import { Head, Link } from '@inertiajs/react';
import Breadcrumb from '../../Components/Breadcrumb';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

/**
 * Order confirmation. Not a template page — Anvogue has none — built on
 * the same breadcrumb + content shell every other inner page uses. The
 * order number shown here is what the customer needs for the public
 * /order-tracking lookup (Q8), which matters for guest checkout: a guest
 * has no account to find the order in later.
 */
export default function CheckoutSuccess({
    order,
}: {
    order: {
        order_number: number;
        total: number;
        customer_status: string;
        items: { name: string; sku: string; quantity: number; subtotal: number }[];
    };
}) {
    const { t, price } = useTranslation();
    return (
        <StorefrontLayout>
            <Head title={t('checkout.successTitle')} />
            <Breadcrumb title={t('checkout.successTitle')} />

            <div className="order-success md:py-20 py-10">
                <div className="container">
                    <div className="max-w-[720px] mx-auto text-center">
                        <span className="ph-fill ph-check-circle text-6xl text-success"></span>
                        <div className="heading3 mt-4">{t('checkout.thankYou')}</div>
                        <div className="body1 text-secondary mt-3">
                            {t('checkout.successOrderLine', {
                                number: order.order_number,
                                total: price(order.total),
                            })}
                        </div>
                    </div>

                    <div className="max-w-[720px] mx-auto border border-line rounded-xl mt-10 p-6">
                        <div className="heading6">{t('checkout.orderSummary')}</div>
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
                            <strong className="heading6">{t('cart.total')}</strong>
                            <strong className="heading6">{price(order.total)}</strong>
                        </div>
                    </div>

                    <div className="flex items-center justify-center gap-4 flex-wrap mt-8">
                        <Link href={route('order-tracking.index')} className="button-main">
                            {t('checkout.trackOrder')}
                        </Link>
                        <Link
                            href={route('shop.index')}
                            className="button-main bg-white text-black border border-black"
                        >
                            {t('cart.continueShopping')}
                        </Link>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
