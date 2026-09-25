import { Head, Link } from '@inertiajs/react';
import ExportButton from '../../Components/ExportButton';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

// Ported from Admin Template/orders-list.html's table conventions.
export default function CheckingIndex({ orders }: { orders: PaginatedData<OrderSummary> }) {
    const { t, dateTime } = useTranslation();
    const { can } = usePermissions();
    return (
        <AdminLayout title={t('admin.checkingWorkQueue')}>
            <Head title={t('admin.checking')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.ordersAwaitingReview')}</h4>
                            {/* Returns needing the same phone call. They live
                                on the returns screen rather than being copied
                                here, so there is one place a return is read. */}
                            {can('returns.check') && can('returns.view') && (
                                <Link
                                    href={route('admin.returns.index', { status: 'requested' })}
                                    className="btn btn-sm btn-soft-primary"
                                >
                                    {t('admin.returnsAwaitingCheck')}
                                </Link>
                            )}
                            {can('checking.export') && <ExportButton href={route('admin.checking.export')} />}
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.order')}</th>
                                        <th>{t('admin.customer')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.total')}</th>
                                        <th>{t('admin.placed')}</th>
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
                                            <td>{order.total}</td>
                                            <td>{dateTime(order.created_at)}</td>
                                            <td>
                                                <Link
                                                    href={route('admin.checking.show', order.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    {t('admin.review')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {orders.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.nothingAwaitingReview')}
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
