import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import { EmptyRow } from '../../Components/EmptyState';
import ExportButton from '../../Components/ExportButton';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import StatCard from '../../Components/StatCard';
import StatusBadge from '../../Components/StatusBadge';
import RowActions from '../../Components/RowActions';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface OrderRow {
    id: number;
    order_number: number;
    status: string;
    payment_status: string;
    total: string;
    created_at: string;
    items_count: number;
    customer: { id: number; name: string; email: string; phone: string } | null;
    delivery_representative: { id: number; name: string } | null;
    shipping_company: { id: number; name: string } | null;
}

/**
 * Ported from Admin Template/orders-list.html: its row of summary tiles
 * above a single `table align-middle table-hover table-centered` card.
 *
 * The template's tiles are fixed demo counters (Payment Refund, Order
 * Cancel, …). These count the statuses this system actually has, and are
 * counted off the same `visibleTo()` scope as the rows below, so a
 * Customer Service Team Leader's totals can never disagree with the list
 * they are sitting on top of.
 *
 * The template's row actions are view/edit/delete. Only view survives
 * here: an order is not an editable record — it moves through Checking,
 * Delivery and Accounting, each of which owns its own transitions — and
 * there is no delete route for one at all.
 */
export default function OrdersIndex({
    orders,
    filters,
    statuses,
    summary,
}: {
    orders: PaginatedData<OrderRow>;
    filters: { status: string; q: string };
    statuses: string[];
    summary: {
        awaiting_checking: number;
        in_delivery: number;
        delivered: number;
        cancelled_or_returned: number;
    };
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();

    // Soft delete, and only offered on an order that is already Cancelled:
    // cancelling is what releases the stock reservation, and deleting does
    // not — so the button is absent rather than disabled-and-explained on
    // an order that still holds stock. The server enforces the same rule.
    async function remove(id: number, orderNumber: number) {
        if (
            !(await confirmAction({
                title: t('admin.deleteOrderQ'),
                text: `#${orderNumber} — ${t('admin.deleteOrderHint')}`,
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.orders.destroy', id), { preserveScroll: true });
    }

    const apply = (next: Partial<{ status: string; q: string }>) =>
        router.get(
            route('admin.orders.index'),
            { status: filters.status, q: filters.q, ...next },
            { preserveState: true, replace: true },
        );

    return (
        <AdminLayout title={t('admin.orderBook')}>
            <Head title={t('admin.orderBook')} />

            <div className="row mb-4">
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.awaitingChecking')}
                        value={summary.awaiting_checking}
                        icon="bx-check-square"
                        variant="warning"
                    />
                </div>
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.inDelivery')}
                        value={summary.in_delivery}
                        icon="bxs-truck"
                        variant="info"
                    />
                </div>
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.delivered')}
                        value={summary.delivered}
                        icon="bx-package"
                        variant="success"
                    />
                </div>
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.cancelledOrReturned')}
                        value={summary.cancelled_or_returned}
                        icon="bx-undo"
                        variant="danger"
                    />
                </div>
            </div>

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.allOrders')}</h4>

                            <SearchFilter
                                value={filters.q ?? ''}
                                placeholder={t('admin.searchOrderOrPhone')}
                                onSubmit={(term) => apply({ q: term })}
                            >
                                <select
                                    className="form-select form-select-sm w-auto"
                                    aria-label={t('admin.allStatuses')}
                                    value={filters.status ?? ''}
                                    onChange={(event) => apply({ status: event.target.value })}
                                >
                                    <option value="">{t('admin.allStatuses')}</option>
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {t(`status.${status}`)}
                                        </option>
                                    ))}
                                </select>
                            </SearchFilter>

                            {can('orders.export') && (
                                <ExportButton
                                    href={route('admin.orders.export', { status: filters.status, q: filters.q })}
                                />
                            )}

                            {can('orders.create') && (
                                <Link
                                    href={route('admin.orders.create')}
                                    className="btn btn-sm btn-primary d-flex align-items-center"
                                >
                                    <i className="bx bx-plus me-1" />
                                    {t('admin.createOrder')}
                                </Link>
                            )}
                        </div>

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th className="ps-3">{t('admin.orderNumber')}</th>
                                        <th>{t('admin.date')}</th>
                                        <th>{t('admin.customer')}</th>
                                        <th>{t('admin.items')}</th>
                                        <th>{t('admin.total')}</th>
                                        <th>{t('admin.paymentStatus')}</th>
                                        <th>{t('admin.assignedTo')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th className="pe-3">{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.data.map((order) => (
                                        <tr key={order.id}>
                                            <td className="ps-3">
                                                <Link
                                                    href={route('admin.orders.show', order.id)}
                                                    className="fw-medium"
                                                    dir="ltr"
                                                >
                                                    #{order.order_number}
                                                </Link>
                                            </td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {dateTime(order.created_at)}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="d-block fw-medium">{order.customer?.name ?? '—'}</span>
                                                <span className="text-muted fs-13" dir="ltr">
                                                    {order.customer?.phone ?? ''}
                                                </span>
                                            </td>
                                            <td dir="ltr">{order.items_count}</td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(order.total))}
                                                </span>
                                            </td>
                                            <td>
                                                <StatusBadge status={order.payment_status} />
                                            </td>
                                            <td>
                                                {order.delivery_representative?.name ??
                                                    order.shipping_company?.name ?? (
                                                        <span className="text-muted">—</span>
                                                    )}
                                            </td>
                                            <td>
                                                <StatusBadge status={order.status} />
                                            </td>
                                            <td className="pe-3">
                                                <RowActions
                                                    viewHref={route('admin.orders.show', order.id)}
                                                    onDelete={
                                                        can('orders.delete') && order.status === 'Cancelled'
                                                            ? () => remove(order.id, order.order_number)
                                                            : undefined
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {orders.data.length === 0 && (
                                        <EmptyRow colSpan={9} message={t('admin.noOrdersMatch')} icon="bx-cart" />
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
