import { Head, Link } from '@inertiajs/react';
import EmptyState, { EmptyRow } from '../Components/EmptyState';
import StatCard from '../Components/StatCard';
import StatusBadge from '../Components/StatusBadge';
import AdminLayout from '../Layouts/AdminLayout';
import { useTranslation } from '../lib/useTranslation';

interface OrderStats {
    today: number;
    awaiting_checking: number;
    out_for_delivery: number;
    delivered_this_month: number;
    revenue_this_month: string;
}

interface LatestOrder {
    id: number;
    order_number: number;
    status: string;
    total: string;
    created_at: string;
    customer: { id: number; name: string } | null;
}

interface LowStockRow {
    id: number;
    sku: string | null;
    product: string | null;
    warehouse: string | null;
    available: number;
}

/**
 * Ported from Admin Template/index.html: the stat-tile grid it opens with
 * (`StatCard`, which is that page's own `card overflow-hidden` tile), and
 * its "Recent Orders" card — a `card-body` header row with a `btn-soft-*`
 * action, then a borderless `table` under a `thead.bg-light.bg-opacity-50`.
 *
 * The template's demo dashboard also carries revenue charts, a
 * conversions radial and a world map. Those are omitted rather than
 * faked: WAQAR has the point-in-time figures below, not the daily series
 * those visuals plot, and filling them with generated numbers is exactly
 * what "do not invent metrics" rules out. The layout is Larkon's; every
 * figure on it is a real query in DashboardController.
 *
 * Each block is also conditional on its prop being present at all — the
 * controller omits the ones the viewer lacks permission for, so a
 * Checking employee gets the order tiles and nothing else, and the grid
 * simply closes up.
 */
export default function Dashboard({
    stats,
    latestOrders,
    lowestStock,
}: {
    stats: {
        orders: OrderStats | null;
        catalog: { active_products: number; total_products: number } | null;
        customers: number | null;
        returns: number | null;
        treasury: string | null;
    };
    latestOrders: LatestOrder[] | null;
    lowestStock: LowStockRow[] | null;
}) {
    const { t, price, dateTime } = useTranslation();

    return (
        <AdminLayout title={t('admin.dashboard')}>
            <Head title={t('admin.dashboard')} />

            <div className="row g-4">
                {stats.orders && (
                    <>
                        <div className="col-md-6 col-xl-3">
                            <StatCard
                                label={t('admin.ordersToday')}
                                value={stats.orders.today}
                                icon="bx-cart-alt"
                                variant="primary"
                                caption={t('admin.awaitingChecking')}
                                href={route('admin.checking.index')}
                                linkLabel={String(stats.orders.awaiting_checking)}
                            />
                        </div>
                        <div className="col-md-6 col-xl-3">
                            <StatCard
                                label={t('admin.outForDelivery')}
                                value={stats.orders.out_for_delivery}
                                icon="bxs-truck"
                                variant="info"
                                caption={t('admin.pendingAction')}
                                href={route('admin.delivery.index')}
                                linkLabel={t('admin.viewAll')}
                            />
                        </div>
                        <div className="col-md-6 col-xl-3">
                            <StatCard
                                label={t('admin.revenueThisMonth')}
                                value={price(Number(stats.orders.revenue_this_month))}
                                icon="bx-wallet"
                                variant="success"
                                caption={t('admin.deliveredThisMonth')}
                                href={route('admin.accounting.index')}
                                linkLabel={String(stats.orders.delivered_this_month)}
                            />
                        </div>
                    </>
                )}

                {stats.returns !== null && (
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.openReturns')}
                            value={stats.returns}
                            icon="bx-undo"
                            variant="warning"
                            caption={t('admin.pendingAction')}
                            href={route('admin.returns.index')}
                            linkLabel={t('admin.viewAll')}
                        />
                    </div>
                )}

                {stats.catalog && (
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.activeProducts')}
                            value={`${stats.catalog.active_products} / ${stats.catalog.total_products}`}
                            icon="bx-package"
                            variant="primary"
                            href={route('admin.products.index')}
                            linkLabel={t('admin.viewAll')}
                        />
                    </div>
                )}

                {stats.customers !== null && (
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.totalCustomers')}
                            value={stats.customers}
                            icon="bx-group"
                            variant="info"
                            href={route('admin.customers.index')}
                            linkLabel={t('admin.viewAll')}
                        />
                    </div>
                )}

                {stats.treasury !== null && (
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.treasuryBalance')}
                            value={price(Number(stats.treasury))}
                            icon="bx-money"
                            variant="success"
                            caption={t('admin.acrossAccounts')}
                            href={route('admin.treasury.index')}
                            linkLabel={t('admin.viewAll')}
                        />
                    </div>
                )}
            </div>

            <div className="row mt-4">
                {latestOrders && (
                    <div className={lowestStock ? 'col-xl-8' : 'col-12'}>
                        <div className="card">
                            <div className="card-body">
                                <div className="d-flex align-items-center justify-content-between gap-2">
                                    <h4 className="card-title">{t('admin.latestOrders')}</h4>
                                    <Link href={route('admin.checking.index')} className="btn btn-sm btn-soft-primary">
                                        {t('admin.viewAll')}
                                    </Link>
                                </div>
                            </div>

                            <div className="table-responsive table-centered">
                                <table className="table mb-0">
                                    <thead className="bg-light bg-opacity-50">
                                        <tr>
                                            <th className="ps-3">{t('admin.orderNumber')}</th>
                                            <th>{t('admin.customer')}</th>
                                            <th>{t('admin.date')}</th>
                                            <th>{t('admin.total')}</th>
                                            <th className="pe-3">{t('admin.status')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {latestOrders.map((order) => (
                                            <tr key={order.id}>
                                                <td className="ps-3">
                                                    <Link
                                                        href={route('admin.checking.show', order.id)}
                                                        dir="ltr"
                                                        className="d-inline-block"
                                                    >
                                                        #{order.order_number}
                                                    </Link>
                                                </td>
                                                <td>{order.customer?.name ?? '—'}</td>
                                                <td>
                                                    <span dir="ltr" className="text-nowrap">
                                                        {dateTime(order.created_at)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(Number(order.total))}
                                                    </span>
                                                </td>
                                                <td className="pe-3">
                                                    <StatusBadge status={order.status} />
                                                </td>
                                            </tr>
                                        ))}
                                        {latestOrders.length === 0 && (
                                            <EmptyRow colSpan={5} message={t('admin.noOrdersYet')} icon="bx-cart" />
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {lowestStock && (
                    <div className={latestOrders ? 'col-xl-4' : 'col-12'}>
                        <div className="card">
                            <div className="card-body">
                                <div className="d-flex align-items-center justify-content-between gap-2">
                                    <h4 className="card-title">{t('admin.lowStock')}</h4>
                                    <Link href={route('admin.inventory.index')} className="btn btn-sm btn-soft-primary">
                                        {t('admin.viewAll')}
                                    </Link>
                                </div>
                            </div>

                            {lowestStock.length === 0 ? (
                                <EmptyState title={t('admin.allStockHealthy')} icon="bx-box" />
                            ) : (
                                <div className="table-responsive table-centered">
                                    <table className="table mb-0">
                                        <thead className="bg-light bg-opacity-50">
                                            <tr>
                                                <th className="ps-3">{t('admin.sku')}</th>
                                                <th className="pe-3">{t('admin.available')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {lowestStock.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="ps-3">
                                                        <span className="d-block fw-medium text-nowrap" dir="ltr">
                                                            {row.sku ?? '—'}
                                                        </span>
                                                        <span className="text-muted fs-12">{row.product ?? '—'}</span>
                                                    </td>
                                                    <td className="pe-3">
                                                        {/* Only the numeral is LTR-isolated. Wrapping
                                                            the whole badge reversed the Arabic unit word
                                                            past the figure. */}
                                                        <span
                                                            className={`badge px-2 py-1 ${
                                                                row.available <= 0
                                                                    ? 'bg-danger-subtle text-danger'
                                                                    : 'bg-warning-subtle text-warning'
                                                            }`}
                                                        >
                                                            <span dir="ltr" className="text-nowrap">
                                                                {row.available}
                                                            </span>{' '}
                                                            {t('admin.units')}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* A viewer with none of the summarised permissions — the spec's
                deferred role-scoped widgets (Q18) are what would eventually
                fill this — still gets a real screen rather than a blank one. */}
            {!stats.orders && !stats.catalog && stats.customers === null && stats.treasury === null && (
                <div className="row g-4">
                    <div className="col-12">
                        <div className="card">
                            <EmptyState
                                title={t('admin.welcomeToWaqarAdmin')}
                                description={t('admin.dashboardIntro')}
                                icon="bx-store"
                            />
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
