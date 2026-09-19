import { Head, Link } from '@inertiajs/react';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface OutstandingOrder extends OrderSummary {
    payments: { id: number; amount: string; collected_amount: string | null }[];
}

export default function AccountingIndex({
    orders,
    outstanding,
}: {
    orders: PaginatedData<OrderSummary>;
    outstanding: PaginatedData<OutstandingOrder>;
}) {
    const { t, price } = useTranslation();
    const { can } = usePermissions();
    const showInvoice = can('orders.view');
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
                                            <td>
                                                <span className="d-block fw-medium">{order.customer?.name ?? '—'}</span>
                                                <span className="text-muted fs-13" dir="ltr">
                                                    {order.customer?.phone ?? ''}
                                                </span>
                                            </td>
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
                                                <div className="d-flex gap-1">
                                                    <Link
                                                        href={route('admin.accounting.show', order.id)}
                                                        className="btn btn-soft-primary btn-sm"
                                                    >
                                                        {t('admin.confirmResult')}
                                                    </Link>
                                                    {showInvoice && (
                                                        <Link
                                                            href={route('admin.orders.invoice', order.id)}
                                                            target="_blank"
                                                            className="btn btn-soft-secondary btn-sm"
                                                            title={t('admin.invoice')}
                                                            aria-label={t('admin.invoice')}
                                                        >
                                                            <i className="bx bx-printer" />
                                                        </Link>
                                                    )}
                                                </div>
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

                    {/* Delivered, but the courier came back short. These
                        have left every other queue, so this is the only
                        place the open money is still visible. */}
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.awaitingBalance')}</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.order')}</th>
                                        <th>{t('admin.customer')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.amountDue')}</th>
                                        <th>{t('admin.collectedSoFar')}</th>
                                        <th>{t('admin.stillOwed')}</th>
                                        <th>{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {outstanding.data.map((order) => {
                                        const payment = order.payments[order.payments.length - 1];
                                        const due = Number(payment?.amount ?? 0);
                                        const collected = Number(payment?.collected_amount ?? 0);

                                        return (
                                            <tr key={order.id}>
                                                <td className="fw-medium">#{order.order_number}</td>
                                                <td>
                                                    <span className="d-block fw-medium">
                                                        {order.customer?.name ?? '—'}
                                                    </span>
                                                    <span className="text-muted fs-13" dir="ltr">
                                                        {order.customer?.phone ?? ''}
                                                    </span>
                                                </td>
                                                <td>
                                                    <StatusBadge status={order.status} />
                                                </td>
                                                <td>
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(due)}
                                                    </span>
                                                </td>
                                                <td className="text-muted">
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(collected)}
                                                    </span>
                                                </td>
                                                <td className="text-danger fw-medium">
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(Math.round((due - collected) * 100) / 100)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <div className="d-flex gap-1">
                                                        <Link
                                                            href={route('admin.accounting.show', order.id)}
                                                            className="btn btn-soft-primary btn-sm"
                                                        >
                                                            {t('admin.recordCollection')}
                                                        </Link>
                                                        {showInvoice && (
                                                            <Link
                                                                href={route('admin.orders.invoice', order.id)}
                                                                target="_blank"
                                                                className="btn btn-soft-secondary btn-sm"
                                                                title={t('admin.invoice')}
                                                                aria-label={t('admin.invoice')}
                                                            >
                                                                <i className="bx bx-printer" />
                                                            </Link>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {outstanding.data.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="text-center text-muted py-4">
                                                {t('admin.nothingAwaitingBalance')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={outstanding} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
