import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import type { Warehouse } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface OrderItem {
    id: number;
    quantity: number;
    unit_price: string;
    // The name and SKU as sold. order_items snapshots both at
    // checkout, so they stay correct — and stay *renderable* —
    // after a product is soft-deleted, which nulls the relation.
    product_name_snapshot: string;
    variant_sku_snapshot: string;
    product_variant: { id: number; sku: string; product: { name: string } | null } | null;
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

interface OrderDetail {
    id: number;
    order_number: number;
    status: string;
    customer: { name: string; email: string; phone: string };
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    total: string;
    items: OrderItem[];
    status_history: StatusHistoryEntry[];
}

type ReasonAction = 'postpone' | 'cancel' | 'backorder';

// Ported from Admin Template/order-detail.html: Product table, Order
// Timeline (the dashed vertical line + circular markers), Customer
// Details card, plus an Actions card for this department's slice of the
// order lifecycle.
export default function CheckingShow({ order, warehouses }: { order: OrderDetail; warehouses: Warehouse[] }) {
    const { t, price, dateTime, isRtl } = useTranslation();
    const [reason, setReason] = useState('');
    const [warehouseId, setWarehouseId] = useState<number | ''>(warehouses[0]?.id ?? '');

    async function confirm() {
        if (!(await confirmAction({ title: t('admin.confirmThisOrder') }))) return;
        router.post(route('admin.checking.confirm', order.id), { notes: reason || undefined });
    }

    async function act(action: ReasonAction) {
        if (!reason.trim()) {
            await confirmAction({ title: 'A reason is required', text: t('admin.enterAReasonFirst') });
            return;
        }
        if (
            !(await confirmAction({
                title: `${action[0].toUpperCase()}${action.slice(1)} this order?`,
                danger: action === 'cancel',
            }))
        )
            return;
        router.post(route(`admin.checking.${action}`, order.id), { reason });
    }

    async function resume() {
        if (!warehouseId) return;
        if (!(await confirmAction({ title: t('admin.resumeFromBackorder'), text: t('admin.stockWillBeReservedNow') })))
            return;
        router.post(route('admin.checking.resume', order.id), { warehouse_id: warehouseId });
    }

    const canAct = ['New', 'Checking', 'Postponed', 'Confirmed', 'Backorder'].includes(order.status);

    return (
        <AdminLayout
            title={t('admin.orderTitle', { number: order.order_number })}
            breadcrumbs={[{ label: t('admin.checkingWorkQueue'), href: route('admin.checking.index') }]}
        >
            <Head title={t('admin.orderNumber', { number: order.order_number })} />

            <div className="row">
                <div className="col-xl-8">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.product')}</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.product')}</th>
                                        <th>SKU</th>
                                        <th>{t('admin.qty')}</th>
                                        <th>{t('admin.unitPrice')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {order.items.map((item) => (
                                        <tr key={item.id}>
                                            <td>{item.product_name_snapshot}</td>
                                            <td className="text-muted">{item.variant_sku_snapshot}</td>
                                            <td>{item.quantity}</td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(item.unit_price))}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
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
                                    {order.status_history.map((h) => (
                                        <div className="mb-4" key={h.id}>
                                            <span className="position-absolute start-0 avatar-sm translate-middle-x bg-light-subtle border d-inline-flex align-items-center justify-content-center rounded-circle">
                                                <i className="bx bx-check text-success fs-18" />
                                            </span>
                                            <div className="ms-2">
                                                {/* Statuses go through StatusBadge so the timeline reads
                                                    in the same language as every other status in the
                                                    admin — these were rendering the server's raw enum
                                                    values. The separator is a Boxicons chevron rather
                                                    than a literal "→": neither Cairo nor Larkon's own
                                                    faces carry U+2192, so it drew as a tofu box, and an
                                                    icon can be pointed the right way for the direction. */}
                                                <h5 className="mb-1 d-flex align-items-center gap-1 flex-wrap fs-15">
                                                    {h.from_status ? (
                                                        <StatusBadge status={h.from_status} />
                                                    ) : (
                                                        <span className="text-muted">—</span>
                                                    )}
                                                    <i
                                                        className={`bx ${isRtl ? 'bx-left-arrow-alt' : 'bx-right-arrow-alt'} text-muted`}
                                                    />
                                                    <StatusBadge status={h.to_status} />
                                                </h5>
                                                <p className="mb-0 text-muted">
                                                    {h.reason ?? h.notes}
                                                    {' — '}
                                                    {t('admin.byPerson', {
                                                        name: h.changed_by?.full_name ?? t('admin.system'),
                                                    })}
                                                </p>
                                                <p className="mb-0 text-muted fs-13">
                                                    <span dir="ltr" className="text-nowrap">
                                                        {dateTime(h.created_at)}
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="col-xl-4">
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
                                    <p className="mb-1 fw-medium">{order.customer.name}</p>
                                    <span className="text-muted">{order.customer.email}</span>
                                </div>
                            </div>

                            <h5 className="mt-3">{t('admin.contactNumber')}</h5>
                            <p className="mb-1">{order.shipping_phone}</p>

                            <h5 className="mt-3">{t('admin.shippingAddress')}</h5>
                            <p className="mb-1">{order.shipping_recipient_name}</p>
                            <p className="mb-0 text-muted">{order.shipping_address_line}</p>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <h4 className="card-title">{t('admin.actions')}</h4>
                            <StatusBadge status={order.status} />
                        </div>
                        <div className="card-body">
                            {order.status === 'Backorder' ? (
                                <>
                                    <div className="mb-3">
                                        <label className="form-label">{t('admin.warehouse')}</label>
                                        <select
                                            className="form-control"
                                            value={warehouseId}
                                            onChange={(e) => setWarehouseId(Number(e.target.value))}
                                        >
                                            {warehouses.map((w) => (
                                                <option key={w.id} value={w.id}>
                                                    {w.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <button type="button" className="btn btn-primary w-100" onClick={resume}>
                                        {t('admin.resumeStockAvailable')}
                                    </button>
                                </>
                            ) : canAct ? (
                                <>
                                    <div className="mb-3">
                                        <label className="form-label">{t('admin.reasonNotes')}</label>
                                        <textarea
                                            className="form-control"
                                            rows={2}
                                            value={reason}
                                            onChange={(e) => setReason(e.target.value)}
                                        />
                                    </div>
                                    <div className="d-grid gap-2">
                                        <button type="button" className="btn btn-success" onClick={confirm}>
                                            {t('admin.confirm')}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-warning"
                                            onClick={() => act('postpone')}
                                        >
                                            {t('admin.postpone')}
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-secondary"
                                            onClick={() => act('backorder')}
                                        >
                                            {t('admin.markBackorder')}
                                        </button>
                                        <button type="button" className="btn btn-danger" onClick={() => act('cancel')}>
                                            {t('admin.cancelOrder')}
                                        </button>
                                    </div>
                                </>
                            ) : (
                                <span className="badge bg-secondary-subtle text-secondary px-2 py-1">
                                    {t('admin.noActionsAvailableAtThisStatus')}
                                </span>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
