import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import OrderSummaryCard from '../../Components/OrderSummaryCard';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
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
    subtotal: string;
    discount_amount: string;
    shipping_amount: string;
    total: string;
    items: OrderItem[];
    status_history: StatusHistoryEntry[];
}

// What Resume would find in the main warehouse right now — the server
// builds this only while the order sits in Backorder.
interface StockCheck {
    warehouse: string | null;
    items: { name: string; required: number; available: number; tracked: boolean }[];
    can_resume: boolean;
}

type ReasonAction = 'postpone' | 'cancel' | 'backorder';

// The confirm prompt used to be built from the action name itself
// (`${action} this order?`), which is only a sentence in English.
const ACTION_QUESTION: Record<ReasonAction, string> = {
    postpone: 'admin.postponeOrderQuestion',
    cancel: 'admin.cancelOrderQuestion',
    backorder: 'admin.backorderOrderQuestion',
};

// Which statuses each button is legal from — the same guard its Phase 3
// Action enforces (ConfirmOrderAction::CONFIRMABLE_FROM and friends).
// Server-side an illegal transition throws, so offering the button at the
// wrong status isn't a no-op, it's a 500: a Confirmed order used to keep
// showing Confirm. Backorder's Resume is handled on its own branch below.
const ALLOWED_FROM: Record<'confirm' | ReasonAction, string[]> = {
    confirm: ['New', 'Checking', 'Postponed'],
    postpone: ['New', 'Checking', 'Confirmed'],
    backorder: ['Confirmed'],
    cancel: ['New', 'Checking', 'Confirmed', 'Postponed', 'Backorder'],
};

// Ported from Admin Template/order-detail.html: Product table, Order
// Timeline (the dashed vertical line + circular markers), Customer
// Details card, plus an Actions card for this department's slice of the
// order lifecycle.
export default function CheckingShow({ order, stock }: { order: OrderDetail; stock: StockCheck | null }) {
    const { t, price, dateTime, isRtl } = useTranslation();
    const [reason, setReason] = useState('');

    async function confirm() {
        if (!(await confirmAction({ title: t('admin.confirmThisOrder') }))) return;
        router.post(route('admin.checking.confirm', order.id), { notes: reason || undefined });
    }

    async function act(action: ReasonAction) {
        if (!reason.trim()) {
            await confirmAction({ title: t('admin.aReasonIsRequired'), text: t('admin.enterAReasonFirst') });
            return;
        }
        if (
            !(await confirmAction({
                title: t(ACTION_QUESTION[action]),
                danger: action === 'cancel',
            }))
        )
            return;
        router.post(route(`admin.checking.${action}`, order.id), { reason });
    }

    async function resume() {
        if (!stock?.can_resume) return;
        if (
            !(await confirmAction({
                title: t('admin.resumeFromBackorder'),
                text: t('admin.stockWillBeReservedIn', { warehouse: stock.warehouse ?? '' }),
            }))
        )
            return;
        router.post(route('admin.checking.resume', order.id));
    }

    const allows = (action: keyof typeof ALLOWED_FROM) => ALLOWED_FROM[action].includes(order.status);
    const canAct = (Object.keys(ALLOWED_FROM) as (keyof typeof ALLOWED_FROM)[]).some(allows);

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
                            {order.status === 'Backorder' && stock ? (
                                <>
                                    <h5 className="mb-2">
                                        {t('admin.stockIn', { warehouse: stock.warehouse ?? '—' })}
                                    </h5>
                                    <div className="table-responsive mb-3">
                                        <table className="table table-sm align-middle mb-0">
                                            <thead className="bg-light-subtle">
                                                <tr>
                                                    <th>{t('admin.product')}</th>
                                                    <th className="text-end">{t('admin.required')}</th>
                                                    <th className="text-end">{t('admin.available')}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {stock.items.map((item, i) => (
                                                    <tr key={i}>
                                                        <td>{item.name}</td>
                                                        <td className="text-end">{item.required}</td>
                                                        <td
                                                            className={`text-end fw-medium ${
                                                                item.tracked && item.available >= item.required
                                                                    ? 'text-success'
                                                                    : 'text-danger'
                                                            }`}
                                                        >
                                                            {/* An Advertisement product has no inventory row at
                                                                all, so "0" would read as a stock problem rather
                                                                than the conversion it actually needs. */}
                                                            {item.tracked
                                                                ? item.available
                                                                : t('admin.notConvertedToReal')}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                    {!stock.can_resume && (
                                        <p className="text-danger fs-13">{t('admin.notEnoughStockToResume')}</p>
                                    )}
                                    <button
                                        type="button"
                                        className="btn btn-primary w-100"
                                        disabled={!stock.can_resume}
                                        onClick={resume}
                                    >
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
                                        {allows('confirm') && (
                                            <button type="button" className="btn btn-success" onClick={confirm}>
                                                {t('admin.confirm')}
                                            </button>
                                        )}
                                        {allows('postpone') && (
                                            <button
                                                type="button"
                                                className="btn btn-warning"
                                                onClick={() => act('postpone')}
                                            >
                                                {t('admin.postpone')}
                                            </button>
                                        )}
                                        {allows('backorder') && (
                                            <button
                                                type="button"
                                                className="btn btn-secondary"
                                                onClick={() => act('backorder')}
                                            >
                                                {t('admin.markBackorder')}
                                            </button>
                                        )}
                                        {allows('cancel') && (
                                            <button
                                                type="button"
                                                className="btn btn-danger"
                                                onClick={() => act('cancel')}
                                            >
                                                {t('admin.cancelOrder')}
                                            </button>
                                        )}
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
