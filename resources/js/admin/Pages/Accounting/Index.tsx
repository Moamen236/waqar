import { Head, Link } from '@inertiajs/react';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

export default function AccountingIndex({ orders }: { orders: PaginatedData<OrderSummary> }) {
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.accountingDeliveryConfirmation')}>
            <Head title={t('admin.accounting')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.ordersAwaitingADeliveryResult')}</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.order')}</th>
                                        <th>{t('admin.customer')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.assignedTo')}</th>
                                        <th>{t('admin.total')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.data.map((order) => (
                                        <tr key={order.id}>
                                            <td className="fw-medium">#{order.order_number}</td>
                                            <td>{order.customer?.name}</td>
                                            <td>
                                                <StatusBadge status={order.status} />
                                            </td>
                                            <td>
                                                {order.delivery_representative?.name ??
                                                    order.shipping_company?.name ??
                                                    '—'}
                                            </td>
                                            <td>{order.total}</td>
                                            <td>
                                                <Link
                                                    href={route('admin.accounting.show', order.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    {t('admin.confirmResult')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {orders.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.nothingAwaitingADeliveryResult')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={orders} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
