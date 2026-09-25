import { Head, Link } from '@inertiajs/react';
import { EmptyRow } from '../../Components/EmptyState';
import OrderSummaryCard from '../../Components/OrderSummaryCard';
import PaymentInstalments, { type Instalment } from '../../Components/PaymentInstalments';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { useTranslation } from '../../lib/useTranslation';

interface OrderItem {
    id: number;
    quantity: number;
    unit_price: string;
    subtotal: string;
    // The name and SKU as sold. order_items snapshots both at checkout, so
    // they stay correct — and stay renderable — after a product is
    // soft-deleted, which nulls the relation.
    product_name_snapshot: string;
    variant_sku_snapshot: string;
}

interface StatusHistoryEntry {
    id: number;
    from_status: string | null;
    to_status: string;
    reason: string | null;
    notes: string | null;
    created_at: string;
    changed_by: { full_name: string } | null;
}

interface Payment {
    id: number;
    amount: string;
    collected_amount: string | null;
    status: string;
    method: string | null;
    collected_method: string | null;
    created_at: string;
    transactions: Instalment[];
}

interface OrderDetail {
    id: number;
    order_number: number;
    status: string;
    customer_status: string;
    payment_status: string;
    order_source: string;
    subtotal: string;
    discount_amount: string;
    shipping_amount: string;
    total: string;
    notes: string | null;
    created_at: string;
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    customer: { id: number; name: string; email: string; phone: string } | null;
    items: OrderItem[];
    status_history: StatusHistoryEntry[];
    payments: Payment[];
    delivery_representative: { id: number; name: string; phone: string } | null;
    shipping_company: { id: number; name: string } | null;
    created_by_employee: { id: number; full_name: string } | null;
    shipping_governorate: { id: number; name: string } | null;
    shipping_city: { id: number; name: string } | null;
    shipping_area: { id: number; name: string } | null;
}

/**
 * Ported from Admin Template/order-detail.html: the `col-xl-9 col-lg-8`
 * column holding the Product table and the Order Timeline, beside a
 * `col-xl-3 col-lg-4` sidebar of Order Summary / Payment Information /
 * Customer Details cards.
 *
 * Read-only. Every status transition belongs to a department screen —
 * Checking confirms, Delivery assigns, Accounting closes — so instead of
 * growing a second set of those buttons this page links to whichever one
 * currently owns the order, and shows nothing when none does (a delivered
 * or cancelled order has no next action).
 */
export default function OrderShow({
    order,
    workflow,
}: {
    order: OrderDetail;
    workflow: { checking: boolean; delivery: boolean; accounting: boolean };
}) {
    const { t, price, dateTime, isRtl } = useTranslation();
    const { can } = usePermissions();

    const destination = [order.shipping_area?.name, order.shipping_city?.name, order.shipping_governorate?.name]
        .filter(Boolean)
        .join('، ');

    return (
        <AdminLayout
            title={t('admin.orderTitle', { number: order.order_number })}
            breadcrumbs={[{ label: t('admin.orderBook'), href: route('admin.orders.index') }]}
            actions={
                <>
                    {can('orders.print_invoice') && (
                        <Link
                            href={route('admin.orders.invoice', order.id)}
                            target="_blank"
                            className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                        >
                            <i className="bx bx-printer" />
                            {t('admin.invoice')}
                        </Link>
                    )}
                    {can('orders.print_label') && (
                        <Link
                            href={route('admin.orders.label', order.id)}
                            target="_blank"
                            className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                        >
                            <i className="bx bx-package" />
                            {t('admin.shippingLabel')}
                        </Link>
                    )}
                    {workflow.checking && (
                        <Link
                            href={route('admin.checking.show', order.id)}
                            className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                        >
                            <i className="bx bx-check-square" />
                            {t('admin.openInChecking')}
                        </Link>
                    )}
                    {workflow.delivery && (
                        <Link
                            href={route('admin.delivery.index')}
                            className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                        >
                            <i className="bx bxs-truck" />
                            {t('admin.openInDelivery')}
                        </Link>
                    )}
                    {workflow.accounting && (
                        <Link
                            href={route('admin.accounting.show', order.id)}
                            className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                        >
                            <i className="bx bx-wallet" />
                            {t('admin.openInAccounting')}
                        </Link>
                    )}
                </>
            }
        >
            <Head title={t('admin.orderTitle', { number: order.order_number })} />

            <div className="row">
                <div className="col-xl-9 col-lg-8">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title">{t('admin.product')}</h4>
                            <div className="d-flex gap-2">
                                <StatusBadge status={order.status} />
                                <StatusBadge status={order.payment_status} />
                            </div>
                        </div>

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th className="ps-3">{t('admin.product')}</th>
                                        <th>SKU</th>
                                        <th>{t('admin.qty')}</th>
                                        <th>{t('admin.unitPrice')}</th>
                                        <th className="pe-3">{t('admin.total')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {order.items.map((item) => (
                                        <tr key={item.id}>
                                            <td className="ps-3 fw-medium">{item.product_name_snapshot}</td>
                                            <td className="text-muted">
                                                <span dir="ltr" className="text-nowrap">
                                                    {item.variant_sku_snapshot}
                                                </span>
                                            </td>
                                            <td dir="ltr">{item.quantity}</td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(item.unit_price))}
                                                </span>
                                            </td>
                                            <td className="pe-3">
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(item.subtotal))}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                    {order.items.length === 0 && (
                                        <EmptyRow colSpan={5} message={t('admin.none')} icon="bx-package" />
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.paymentInformation')}</h4>
                        </div>
                        <div className="card-body">
                            {order.payments.length === 0 ? (
                                <p className="text-muted mb-0">{t('admin.noPaymentsRecorded')}</p>
                            ) : (
                                order.payments.map((payment) => (
                                    <div key={payment.id} className="mb-3">
                                        <div className="d-flex justify-content-between gap-2">
                                            <div>
                                                <span className="d-block fw-medium" dir="ltr">
                                                    {price(Number(payment.amount))}
                                                </span>
                                                <span className="text-muted fs-13">
                                                    {payment.collected_method
                                                        ? t(`collectedMethod.${payment.collected_method}`)
                                                        : (payment.method ?? '—')}
                                                </span>
                                            </div>
                                            <StatusBadge status={payment.status} />
                                        </div>

                                        {/* Only worth spelling out once money has
                                            actually moved — before that the single
                                            row above says everything. */}
                                        {payment.transactions.length > 0 && (
                                            <>
                                                <h5 className="fs-13 text-muted mt-3 mb-1">
                                                    {t('admin.paymentCollections')}
                                                </h5>
                                                <PaymentInstalments instalments={payment.transactions} />
                                                {payment.status === 'partially_collected' && (
                                                    <p className="text-danger fs-13 mt-2 mb-0">
                                                        {t('admin.stillOwed')}:{' '}
                                                        <span dir="ltr">
                                                            {price(
                                                                Number(payment.amount) -
                                                                    Number(payment.collected_amount ?? 0),
                                                            )}
                                                        </span>
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.orderTimeline')}</h4>
                        </div>
                        <div className="card-body">
                            {order.status_history.length === 0 && (
                                <p className="text-muted mb-0">{t('admin.noChangesYet')}</p>
                            )}
                            <div className="position-relative ms-2">
                                {order.status_history.length > 0 && (
                                    <span className="position-absolute start-0 top-0 border border-dashed h-100" />
                                )}
                                <div className="position-relative ps-4">
                                    {order.status_history.map((entry) => (
                                        <div className="mb-4" key={entry.id}>
                                            <span className="position-absolute start-0 avatar-sm translate-middle-x bg-light-subtle border d-inline-flex align-items-center justify-content-center rounded-circle">
                                                <i className="bx bx-check text-success fs-18" />
                                            </span>
                                            <div className="ms-2">
                                                <h5 className="mb-1 d-flex align-items-center gap-1 flex-wrap fs-15">
                                                    {entry.from_status ? (
                                                        <StatusBadge status={entry.from_status} />
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                    <i
                                                        className={`bx ${isRtl ? 'bx-left-arrow-alt' : 'bx-right-arrow-alt'} text-muted`}
                                                    />
                                                    <StatusBadge status={entry.to_status} />
                                                </h5>
                                                {(entry.reason || entry.notes) && (
                                                    <p className="mb-0 text-muted">{entry.reason ?? entry.notes}</p>
                                                )}
                                                <p className="mb-0 text-muted fs-13">
                                                    {t('admin.byPerson', {
                                                        name: entry.changed_by?.full_name ?? t('admin.system'),
                                                    })}
                                                </p>
                                                <p className="mb-0 text-muted fs-13">
                                                    <span dir="ltr">{dateTime(entry.created_at)}</span>
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="col-xl-3 col-lg-4">
                    <OrderSummaryCard order={order} />

                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.customerDetails')}</h4>
                        </div>
                        <div className="card-body">
                            <div className="d-flex align-items-center gap-2">
                                <div className="avatar rounded-3 bg-light-subtle border d-flex align-items-center justify-content-center">
                                    <i className="bx bx-user fs-20" />
                                </div>
                                <div>
                                    <span className="d-block fw-medium">{order.customer?.name ?? '—'}</span>
                                    <span className="text-muted fs-13" dir="ltr">
                                        {order.customer?.email ?? ''}
                                    </span>
                                </div>
                            </div>

                            <ul className="list-unstyled d-flex flex-column gap-2 mt-3 mb-0 fs-14">
                                <DetailRow label={t('admin.phone')} value={order.shipping_phone} ltr />
                                <DetailRow
                                    label={t('admin.shippingAddress')}
                                    value={`${order.shipping_recipient_name} — ${order.shipping_address_line}${destination ? `، ${destination}` : ''}`}
                                />
                                <DetailRow
                                    label={t('admin.assignedTo')}
                                    value={
                                        order.delivery_representative?.name ??
                                        order.shipping_company?.name ??
                                        t('admin.none')
                                    }
                                />
                                <DetailRow
                                    label={t('admin.orderSource')}
                                    value={t(`orderSource.${order.order_source}`)}
                                />
                                {order.created_by_employee && (
                                    <DetailRow
                                        label={t('admin.placedBy')}
                                        value={order.created_by_employee.full_name}
                                    />
                                )}
                                <DetailRow label={t('admin.date')} value={dateTime(order.created_at)} ltr />
                            </ul>

                            {order.notes && (
                                <div className="mt-3">
                                    <h5 className="fs-14 mb-1">{t('admin.notes')}</h5>
                                    <p className="text-muted mb-0">{order.notes}</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

function DetailRow({ label, value, ltr = false }: { label: string; value: string; ltr?: boolean }) {
    return (
        <li>
            <span className="text-muted">{label}</span>
            <span className="mx-1">:</span>
            {ltr ? <span dir="ltr">{value}</span> : <span className="text-dark">{value}</span>}
        </li>
    );
}
