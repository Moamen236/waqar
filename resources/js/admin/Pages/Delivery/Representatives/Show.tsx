import { Head, Link } from '@inertiajs/react';
import StatCard from '../../../Components/StatCard';
import StatusBadge from '../../../Components/StatusBadge';
import { EmptyRow } from '../../../Components/EmptyState';
import { PaginationFooter } from '../../../Components/Pagination';
import AdminLayout from '../../../Layouts/AdminLayout';
import { usePermissions } from '../../../Hooks/usePermissions';
import type { GeoTree, PaginatedData } from '../../../types';
import { useTranslation } from '../../../lib/useTranslation';

interface Representative {
    id: number;
    name: string;
    phone: string;
    status: string;
    notes: string | null;
    areas_count: number;
}

interface CoverageArea {
    id: number;
    geo_type: 'governorate' | 'city' | 'district' | 'area';
    geo_id: number;
}

interface ProfileOrder {
    id: number;
    order_number: number;
    status: string;
    payment_status: string;
    total: string;
    shipping_amount: string;
    created_at: string;
    customer: { id: number; name: string; phone: string } | null;
    shipping_governorate: { id: number; name: string } | null;
    shipping_city: { id: number; name: string } | null;
    shipping_district: { id: number; name: string } | null;
    shipping_area: { id: number; name: string } | null;
    // Computed server-side (RepresentativeController::show): the goods
    // money the courier should hand over, what they already handed over,
    // and what is still out with them. still_owed is non-zero only on
    // delivered-but-short (partially_collected) orders.
    net_due: number;
    collected_amount: number;
    still_owed: number;
    is_delivered: boolean;
}

interface Stats {
    total_orders: number;
    active_orders: number;
    delivered_orders: number;
    partially_returned_orders: number;
    returned_orders: number;
    outstanding_orders: number;
    outstanding_balance: number;
    collected_total: number;
}

// One delivery man's profile: who they are, where they deliver, and every
// order on their back — delivered orders split into settled vs. still-owed
// so the cash still out with this courier is visible at a glance.
export default function RepresentativeShow({
    representative,
    areas,
    geoTree,
    stats,
    orders,
}: {
    representative: Representative;
    areas: CoverageArea[];
    geoTree: GeoTree;
    // Null without delivery.view — the courier's order history is Delivery
    // Board data, not something delivery.representatives.view hands out.
    stats: Stats | null;
    orders: PaginatedData<ProfileOrder> | null;
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();

    function labelFor(area: CoverageArea): string {
        for (const g of geoTree) {
            if (area.geo_type === 'governorate' && g.id === area.geo_id) return `${g.name}`;
            for (const c of g.cities) {
                if (area.geo_type === 'city' && c.id === area.geo_id) return `${c.name}`;
                for (const d of c.districts) {
                    if (area.geo_type === 'district' && d.id === area.geo_id) return `${d.name}`;
                }
                for (const a of c.areas) {
                    if (area.geo_type === 'area' && a.id === area.geo_id) return `${a.name}`;
                }
            }
        }
        return `#${area.geo_id}`;
    }

    function locationOf(order: ProfileOrder): string {
        const parts = [
            order.shipping_governorate?.name,
            order.shipping_city?.name,
            order.shipping_district?.name,
            order.shipping_area?.name,
        ].filter(Boolean);
        return parts.length > 0 ? parts.join(' — ') : '—';
    }

    return (
        <AdminLayout
            title={representative.name}
            breadcrumbs={[
                {
                    label: t('admin.deliveryRepresentatives'),
                    href: route('admin.delivery.representatives.index'),
                },
            ]}
            actions={
                <div className="d-flex gap-1">
                    <Link
                        href={route('admin.delivery.representatives.areas', representative.id)}
                        className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                    >
                        <i className="bx bx-map" />
                        {t('admin.manageCoverage')}
                    </Link>
                    {can('delivery.representatives.update') && (
                        <Link
                            href={route('admin.delivery.representatives.edit', representative.id)}
                            className="btn btn-sm btn-soft-secondary d-flex align-items-center gap-1"
                        >
                            <i className="bx bx-edit" />
                            {t('admin.edit')}
                        </Link>
                    )}
                </div>
            }
        >
            <Head title={`${t('admin.representativeProfile')}: ${representative.name}`} />

            {stats && (
                <div className="row g-3 mb-3">
                    <div className="col-md-6 col-xl-3">
                        <StatCard label={t('admin.totalOrders')} value={stats.total_orders} icon="bx-package" />
                    </div>
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.onTheRoad')}
                            value={stats.active_orders}
                            icon="bx-truck"
                            variant="info"
                        />
                    </div>
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.deliveredOrders')}
                            value={stats.delivered_orders}
                            icon="bx-check-circle"
                            variant="success"
                            caption={`${t('admin.partiallyReturned')}: ${stats.partially_returned_orders} · ${t('admin.returned')}: ${stats.returned_orders}`}
                        />
                    </div>
                    <div className="col-md-6 col-xl-3">
                        <StatCard
                            label={t('admin.outstandingBalance')}
                            value={price(stats.outstanding_balance)}
                            icon="bx-wallet"
                            variant={stats.outstanding_balance > 0 ? 'danger' : 'success'}
                            caption={`${t('admin.outstanding')}: ${stats.outstanding_orders} · ${t('admin.collected')}: ${price(stats.collected_total)}`}
                        />
                    </div>
                </div>
            )}

            <div className="row g-3 mb-3">
                <div className="col-xl-4">
                    <div className="card h-100">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <h4 className="card-title">{t('admin.representativeProfile')}</h4>
                            <StatusBadge status={representative.status} />
                        </div>
                        <div className="card-body">
                            <ul className="list-unstyled mb-0 fs-13">
                                <li className="d-flex justify-content-between gap-2 mb-1">
                                    <span className="text-muted">{t('admin.name')}</span>
                                    <span className="text-dark fw-medium">{representative.name}</span>
                                </li>
                                <li className="d-flex justify-content-between gap-2 mb-1">
                                    <span className="text-muted">{t('admin.phone')}</span>
                                    <span dir="ltr">{representative.phone}</span>
                                </li>
                                <li className="d-flex justify-content-between gap-2 mb-1">
                                    <span className="text-muted">{t('admin.coverageAreas')}</span>
                                    <span className="text-dark">{representative.areas_count}</span>
                                </li>
                                {representative.notes && (
                                    <li className="mt-2">
                                        <span className="text-muted d-block">{t('admin.notes')}</span>
                                        <span className="text-dark">{representative.notes}</span>
                                    </li>
                                )}
                            </ul>
                        </div>
                    </div>
                </div>

                <div className="col-xl-8">
                    <div className="card h-100">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.currentCoverage')}</h4>
                        </div>
                        <ul className="list-group list-group-flush">
                            {areas.map((area) => (
                                <li
                                    key={area.id}
                                    className="list-group-item d-flex justify-content-between align-items-center"
                                >
                                    <span>
                                        <i className="bx bx-map-pin text-muted me-1" />
                                        {labelFor(area)}
                                    </span>
                                    <span className="badge bg-soft-secondary text-secondary">
                                        {t(`admin.${area.geo_type}`)}
                                    </span>
                                </li>
                            ))}
                            {areas.length === 0 && (
                                <li className="list-group-item text-muted">{t('admin.noCoverageAreasYet')}</li>
                            )}
                        </ul>
                    </div>
                </div>
            </div>

            {orders && (
                <div className="card">
                    <div className="card-header">
                        <h4 className="card-title">{t('admin.allOrders')}</h4>
                    </div>
                    <div className="table-responsive">
                        <table className="table align-middle mb-0 table-hover table-centered">
                            <thead className="bg-light-subtle">
                                <tr>
                                    <th>{t('admin.order')}</th>
                                    <th>{t('admin.customer')}</th>
                                    <th>{t('admin.location')}</th>
                                    <th>{t('admin.status')}</th>
                                    <th>{t('admin.payment')}</th>
                                    <th>{t('admin.netDue')}</th>
                                    <th>{t('admin.collected')}</th>
                                    <th>{t('admin.stillOwed')}</th>
                                    <th>{t('admin.createdAt')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {orders.data.map((order) => (
                                    <tr key={order.id} className={order.still_owed > 0 ? 'table-warning' : undefined}>
                                        <td>
                                            {can('orders.view') ? (
                                                <Link href={route('admin.orders.show', order.id)} className="fw-medium">
                                                    <span dir="ltr">#{order.order_number}</span>
                                                </Link>
                                            ) : (
                                                <span dir="ltr" className="fw-medium">
                                                    #{order.order_number}
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            {order.customer?.name ?? '—'}
                                            {order.customer && (
                                                <div className="text-muted fs-12" dir="ltr">
                                                    {order.customer.phone}
                                                </div>
                                            )}
                                        </td>
                                        <td className="text-muted fs-13">{locationOf(order)}</td>
                                        <td>
                                            <StatusBadge status={order.status} />
                                        </td>
                                        <td>
                                            <StatusBadge status={order.payment_status} />
                                        </td>
                                        <td>
                                            <span dir="ltr" className="text-nowrap">
                                                {price(order.net_due)}
                                            </span>
                                        </td>
                                        <td>
                                            <span dir="ltr" className="text-nowrap text-muted">
                                                {price(order.collected_amount)}
                                            </span>
                                        </td>
                                        <td>
                                            {order.still_owed > 0 ? (
                                                <span dir="ltr" className="text-nowrap fw-semibold text-danger">
                                                    {price(order.still_owed)}
                                                </span>
                                            ) : (
                                                <span className="text-muted">—</span>
                                            )}
                                        </td>
                                        <td>
                                            <span dir="ltr" className="text-nowrap text-muted fs-13">
                                                {dateTime(order.created_at)}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                                {orders.data.length === 0 && (
                                    <EmptyRow colSpan={9} message={t('admin.noOrdersForRepresentative')} />
                                )}
                            </tbody>
                        </table>
                    </div>
                    <PaginationFooter data={orders} />
                </div>
            )}
        </AdminLayout>
    );
}
