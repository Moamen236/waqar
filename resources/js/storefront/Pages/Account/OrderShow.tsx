import { Head, router } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import StatusTag from '../../Components/StatusTag';
import Timeline from '../../Components/Timeline';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import type { OrderTimelineData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface OrderDetail {
    order_number: number;
    status: string;
    placed_at: string | null;
    subtotal: number;
    discount_amount: number;
    shipping_amount: number;
    total: number;
    cancellable: boolean;
    address: {
        recipient_name: string;
        phone: string;
        line: string;
        area: string | null;
        district: string | null;
        city: string | null;
        governorate: string | null;
    };
    items: { name: string; sku: string; quantity: number; unit_price: number; subtotal: number }[];
}

/**
 * Order detail — the template's `modal-order-detail` content promoted to
 * its own page, so an order is linkable from the account list and from
 * the confirmation email when those land.
 */
export default function AccountOrderShow({ order, timeline }: { order: OrderDetail; timeline: OrderTimelineData }) {
    const { t, price } = useTranslation();
    const address = [
        order.address.line,
        order.address.area,
        order.address.district,
        order.address.city,
        order.address.governorate,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <StorefrontLayout>
            <Head title={`Order #${order.order_number}`} />
            <Breadcrumb
                title={`Order #${order.order_number}`}
                parent={{ label: 'My Orders', href: route('account.orders') }}
            />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="orders" />
                        <div className="right list-filter md:w-2/3 w-full ps-2.5">
                            <div className="text-content w-full p-7 border border-line rounded-xl">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h6 className="heading6">
                                            {t('order.numbered', { number: order.order_number })}
                                        </h6>
                                        <div className="caption1 text-secondary mt-1">
                                            {t('order.placedOn', { date: order.placed_at ?? '' })}
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
                                        className="flex flex-wrap items-center justify-between gap-3 py-4 border-b border-line"
                                    >
                                        <div>
                                            <div className="text-title">{item.name}</div>
                                            <div className="caption1 text-secondary mt-1">
                                                {item.sku} · {item.quantity} × {price(item.unit_price)}
                                            </div>
                                        </div>
                                        <div className="text-title">{price(item.subtotal)}</div>
                                    </div>
                                ))}

                                <div className="totals mt-6">
                                    <div className="flex items-center justify-between py-2">
                                        <div className="text-secondary">{t('cart.subtotal')}</div>
                                        <div className="text-title">{price(order.subtotal)}</div>
                                    </div>
                                    {order.discount_amount > 0 && (
                                        <div className="flex items-center justify-between py-2">
                                            <div className="text-secondary">{t('cart.discounts')}</div>
                                            <div className="text-title">-{price(order.discount_amount)}</div>
                                        </div>
                                    )}
                                    <div className="flex items-center justify-between py-2">
                                        <div className="text-secondary">{t('cart.shipping')}</div>
                                        <div className="text-title">{price(order.shipping_amount)}</div>
                                    </div>
                                    <div className="flex items-center justify-between py-3 border-t border-line mt-2">
                                        <strong className="heading6">{t('order.totalCod')}</strong>
                                        <strong className="heading6">{price(order.total)}</strong>
                                    </div>
                                </div>

                                <div className="heading6 mt-10">{t('order.deliveryAddress')}</div>
                                <div className="caption1 text-secondary mt-2">
                                    {order.address.recipient_name} · {order.address.phone}
                                    <br />
                                    {address}
                                </div>

                                {order.cancellable && (
                                    <button
                                        type="button"
                                        className="button-main bg-surface border border-line hover:bg-black text-black hover:text-white mt-8"
                                        onClick={() =>
                                            router.post(
                                                route('account.orders.cancel', order.order_number),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('account.cancelOrder')}
                                    </button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
