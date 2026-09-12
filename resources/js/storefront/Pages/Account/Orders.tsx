import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import StatusTag from '../../Components/StatusTag';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

interface OrderCard {
    order_number: number;
    status: string;
    total: number;
    placed_at: string | null;
    cancellable: boolean;
    items: { name: string; sku: string; quantity: number; unit_price: number }[];
}

/**
 * my-account.html's Orders tab (`tab_order` / `list_order` markup). The
 * template's all/pending/delivery/completed/canceled tabs become filters
 * over the real customer-facing statuses (Section 03) — "Completed"
 * isn't a status in this system, "Delivered" is.
 */
const filters = [
    { key: 'all', label: 'account.filterAll', match: () => true },
    {
        key: 'processing',
        label: 'account.filterProcessing',
        match: (s: string) => ['Order Received', 'Processing'].includes(s),
    },
    {
        key: 'shipping',
        label: 'account.filterShipping',
        match: (s: string) => ['Shipping', 'Out for Delivery'].includes(s),
    },
    { key: 'delivered', label: 'account.filterDelivered', match: (s: string) => s === 'Delivered' },
    { key: 'cancelled', label: 'account.filterCancelled', match: (s: string) => ['Cancelled', 'Returned'].includes(s) },
] as const;

export default function AccountOrders({ orders }: { orders: OrderCard[] }) {
    const [active, setActive] = useState<string>('all');
    const { t, price } = useTranslation();
    const matcher = filters.find((filter) => filter.key === active) ?? filters[0];
    const visible = orders.filter((order) => matcher.match(order.status));

    return (
        <StorefrontLayout>
            <Head title={t('account.navOrders')} />
            <Breadcrumb title={t('account.navOrders')} />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="orders" />
                        <div className="right list-filter md:w-2/3 w-full ps-2.5">
                            <div className="tab_order text-content overflow-hidden w-full p-7 border border-line rounded-xl">
                                <h6 className="heading6">{t('account.yourOrders')}</h6>
                                <div className="w-full overflow-x-auto">
                                    <div className="menu-tab relative grid grid-cols-5 max-lg:w-[500px] max-md:max-w-max border-b border-line mt-3">
                                        {filters.map((filter) => (
                                            <button
                                                key={filter.key}
                                                type="button"
                                                className={`tab-item relative px-3 py-2.5 text-button text-center duration-300 hover:text-black ${
                                                    active === filter.key
                                                        ? 'active text-black border-b-2 border-black'
                                                        : 'text-secondary'
                                                }`}
                                                onClick={() => setActive(filter.key)}
                                            >
                                                {t(filter.label)}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                                <div className="list_order">
                                    {visible.length === 0 && (
                                        <div className="caption1 text-secondary py-8">{t('account.noOrdersHere')}</div>
                                    )}
                                    {visible.map((order) => (
                                        <div
                                            key={order.order_number}
                                            className="order_item mt-5 border border-line rounded-lg box-shadow-xs"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-4 p-5 border-b border-line">
                                                <div className="flex items-center gap-2">
                                                    <strong className="text-title">{t('account.orderNumber')}</strong>
                                                    <strong className="order_number text-button uppercase">
                                                        #{order.order_number}
                                                    </strong>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <strong className="text-title">{t('account.orderStatus')}</strong>
                                                    <StatusTag status={order.status} />
                                                </div>
                                            </div>
                                            <div className="list_prd px-5">
                                                {order.items.map((item) => (
                                                    <div
                                                        key={item.sku}
                                                        className="prd_item flex flex-wrap items-center justify-between gap-3 py-5 border-b border-line"
                                                    >
                                                        <div className="flex items-center gap-5">
                                                            <div>
                                                                <div className="prd_name text-title">{item.name}</div>
                                                                <div className="caption1 text-secondary mt-2">
                                                                    {item.sku}
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div className="text-title">
                                                            <span className="prd_quantity">{item.quantity}</span>
                                                            <span> × </span>
                                                            <span className="prd_price">{price(item.unit_price)}</span>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                            <div className="flex flex-wrap items-center gap-4 p-5">
                                                <Link
                                                    href={route('account.orders.show', order.order_number)}
                                                    className="button-main btn_order_detail"
                                                >
                                                    {t('account.orderDetails')}
                                                </Link>
                                                {order.cancellable && (
                                                    <button
                                                        type="button"
                                                        className="button-main bg-surface border border-line hover:bg-black text-black hover:text-white"
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
                                                <div className="ms-auto text-title">{price(order.total)}</div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
