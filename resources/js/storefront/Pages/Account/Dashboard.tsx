import { Head, Link } from '@inertiajs/react';
import AccountNav from '../../Components/AccountNav';
import Breadcrumb from '../../Components/Breadcrumb';
import StatusTag from '../../Components/StatusTag';
import StorefrontLayout from '../../Layouts/StorefrontLayout';
import { useTranslation } from '../../lib/useTranslation';

interface OrderRow {
    order_number: number;
    status: string;
    total: number;
    placed_at: string | null;
    product_name: string | null;
    item_count: number;
}

/** my-account.html's Dashboard tab — overview tiles + recent orders table. */
export default function AccountDashboard({
    stats,
    recentOrders,
}: {
    stats: { awaiting: number; cancelled: number; total: number };
    recentOrders: OrderRow[];
}) {
    const { t, price } = useTranslation();
    return (
        <StorefrontLayout>
            <Head title={t('account.title')} />
            <Breadcrumb title={t('account.title')} />

            <div className="my-account-block md:py-20 py-10">
                <div className="container">
                    <div className="content-main lg:px-[60px] md:px-4 flex gap-y-8 max-md:flex-col w-full">
                        <AccountNav active="dashboard" />
                        <div className="right list-filter md:w-2/3 w-full ps-2.5">
                            <div className="overview grid sm:grid-cols-3 gap-5">
                                <div className="overview-item flex items-center justify-between p-5 border border-line rounded-lg box-shadow-xs">
                                    <div className="counter">
                                        <span className="text-secondary">{t('account.inProgress')}</span>
                                        <h5 className="heading5 mt-1">{stats.awaiting}</h5>
                                    </div>
                                    <span className="ph ph-hourglass-medium text-4xl"></span>
                                </div>
                                <div className="overview-item flex items-center justify-between p-5 border border-line rounded-lg box-shadow-xs">
                                    <div className="counter">
                                        <span className="text-secondary">{t('account.cancelledOrders')}</span>
                                        <h5 className="heading5 mt-1">{stats.cancelled}</h5>
                                    </div>
                                    <span className="ph ph-receipt-x text-4xl"></span>
                                </div>
                                <div className="overview-item flex items-center justify-between p-5 border border-line rounded-lg box-shadow-xs">
                                    <div className="counter">
                                        <span className="text-secondary">{t('account.totalOrders')}</span>
                                        <h5 className="heading5 mt-1">{stats.total}</h5>
                                    </div>
                                    <span className="ph ph-package text-4xl"></span>
                                </div>
                            </div>

                            <div className="recent_order pt-5 px-5 pb-2 mt-7 border border-line rounded-xl">
                                <h6 className="heading6">{t('account.recentOrders')}</h6>
                                <div className="list overflow-x-auto w-full mt-5">
                                    <table className="w-full max-[1400px]:w-[700px] max-md:w-[700px]">
                                        <thead className="border-b border-line">
                                            <tr>
                                                <th className="pb-3 text-start text-sm font-bold uppercase text-secondary whitespace-nowrap">
                                                    {t('account.colOrder')}
                                                </th>
                                                <th className="pb-3 text-start text-sm font-bold uppercase text-secondary whitespace-nowrap">
                                                    {t('account.colProducts')}
                                                </th>
                                                <th className="pb-3 text-start text-sm font-bold uppercase text-secondary whitespace-nowrap">
                                                    {t('account.colPricing')}
                                                </th>
                                                <th className="pb-3 text-end text-sm font-bold uppercase text-secondary whitespace-nowrap">
                                                    {t('account.colStatus')}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {recentOrders.length === 0 && (
                                                <tr>
                                                    <td colSpan={4} className="py-6 caption1 text-secondary">
                                                        {t('account.noOrders')}
                                                    </td>
                                                </tr>
                                            )}
                                            {recentOrders.map((order) => (
                                                <tr
                                                    key={order.order_number}
                                                    className="item duration-300 border-b border-line"
                                                >
                                                    <th scope="row" className="py-3 text-start">
                                                        <Link
                                                            href={route('account.orders.show', order.order_number)}
                                                            className="text-title"
                                                        >
                                                            #{order.order_number}
                                                        </Link>
                                                    </th>
                                                    <td className="py-3">
                                                        <div className="info flex flex-col">
                                                            <strong className="product_name text-button">
                                                                {order.product_name ?? '—'}
                                                            </strong>
                                                            <span className="product_tag caption1 text-secondary">
                                                                {t('account.itemCount', { count: order.item_count })} ·{' '}
                                                                {order.placed_at}
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td className="py-3 price">{price(order.total)}</td>
                                                    <td className="py-3 text-end">
                                                        <StatusTag status={order.status} />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </StorefrontLayout>
    );
}
