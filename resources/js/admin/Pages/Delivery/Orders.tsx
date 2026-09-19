import { Head, Link } from '@inertiajs/react';
import { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

// Orders already handed off — Assigned and Out for Delivery. Read-only:
// the next transition on these is Accounting's, not Delivery's.
export default function DeliveryOrders({ orders }: { orders: PaginatedData<OrderSummary> }) {
    const { t, price, dateTime } = useTranslation();

    return (
        <AdminLayout
            title={t('admin.navOutForDelivery')}
            breadcrumbs={[{ label: t('admin.deliveryAssignmentBoard'), href: route('admin.delivery.index') }]}
        >
            <Head title={t('admin.navOutForDelivery')} />

            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <h4 className="card-title">{t('admin.navOutForDelivery')}</h4>
                    <Link href={route('admin.delivery.index')} className="btn btn-sm btn-outline-secondary">
                        {t('admin.readyToAssignConfirmed')}
                    </Link>
                </div>
                <div className="table-responsive">
                    <table className="table align-middle mb-0 table-centered">
                        <thead className="bg-light-subtle">
                            <tr>
                                <th>{t('admin.order')}</th>
                                <th>{t('admin.customer')}</th>
                                <th>{t('admin.governorate')}</th>
                                <th>{t('admin.city')}</th>
                                <th>{t('admin.district')}</th>
                                <th>{t('admin.area')}</th>
                                <th>{t('admin.status')}</th>
                                <th>{t('admin.assignedTo')}</th>
                                <th>{t('admin.total')}</th>
                                <th>{t('admin.createdAt')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {orders.data.map((order) => (
                                <tr key={order.id}>
                                    <td>#{order.order_number}</td>
                                    <td>{order.customer?.name}</td>
                                    <td>{order.shipping_governorate?.name ?? '—'}</td>
                                    <td>{order.shipping_city?.name ?? '—'}</td>
                                    <td className="text-muted">{order.shipping_district?.name ?? '—'}</td>
                                    <td>{order.shipping_area?.name ?? '—'}</td>
                                    <td>
                                        <StatusBadge status={order.status} />
                                    </td>
                                    <td>
                                        {order.delivery_representative?.name ?? order.shipping_company?.name ?? '—'}
                                    </td>
                                    <td>
                                        <span dir="ltr" className="text-nowrap">
                                            {price(Number(order.total))}
                                        </span>
                                    </td>
                                    <td>
                                        <span dir="ltr" className="text-nowrap">
                                            {dateTime(order.created_at)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                            {orders.data.length === 0 && (
                                <EmptyRow colSpan={10} message={t('admin.nothingOutForDelivery')} />
                            )}
                        </tbody>
                    </table>
                </div>
                <PaginationFooter data={orders} />
            </div>
        </AdminLayout>
    );
}
