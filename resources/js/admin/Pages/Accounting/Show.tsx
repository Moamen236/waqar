import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Tab from 'react-bootstrap/Tab';
import Tabs from 'react-bootstrap/Tabs';
import OrderSummaryCard from '../../Components/OrderSummaryCard';
import PaymentInstalments, { type Instalment } from '../../Components/PaymentInstalments';
import ShippingAddressCard from '../../Components/ShippingAddressCard';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import type { GeoName } from '../../types';
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

interface Payment {
    id: number;
    // What this order is owed — the order total, or, after a partial
    // return, the recomputed value of what the customer kept.
    amount: string;
    collected_amount: string | null;
    status: string;
    transactions: Instalment[];
}

interface DeliveryAssignment {
    id: number;
    assignment_type: string;
    assigned_at: string;
    assigned_by: { id: number; full_name: string } | null;
}

interface OrderDetail {
    id: number;
    order_number: number;
    status: string;
    payment_status: string;
    delivery_assignment_type: string | null;
    delivery_representative: { id: number; name: string; phone: string } | null;
    shipping_company: { id: number; name: string; phone: string; contact_person: string | null } | null;
    delivery_assignments: DeliveryAssignment[];
    payments: Payment[];
    subtotal: string;
    discount_amount: string;
    shipping_amount: string;
    total: string;
    customer: { name: string; email: string; phone: string };
    shipping_recipient_name: string;
    shipping_phone: string;
    shipping_address_line: string;
    shipping_governorate: GeoName | null;
    shipping_city: GeoName | null;
    shipping_district: GeoName | null;
    shipping_area: GeoName | null;
    items: OrderItem[];
}

interface Treasury {
    id: number;
    name: string;
    type: string;
}

const COLLECTED_METHODS = ['cash', 'bank_transfer', 'wallet', 'other'];

// Every confirmation lands back on this same page component, and post()
// preserves local state by default — which would leave the old amounts and
// kept quantities sitting next to the new status. Remount from fresh props.
const FRESH = { preserveState: false, preserveScroll: true };

// Ported from Admin Template/order-detail.html's Product table +
// Customer Details / Payment Information cards.
export default function AccountingShow({ order, treasuries }: { order: OrderDetail; treasuries: Treasury[] }) {
    const { t, price, dateTime } = useTranslation();
    const [treasuryId, setTreasuryId] = useState<number | ''>(treasuries[0]?.id ?? '');
    const [collectedMethod, setCollectedMethod] = useState('cash');
    const [collectedAmount, setCollectedAmount] = useState(order.total);
    const [keptQuantities, setKeptQuantities] = useState<Record<number, number>>(
        Object.fromEntries(order.items.map((item) => [item.id, item.quantity])),
    );

    const canAct = ['Assigned', 'Out for Delivery'].includes(order.status);

    // Whoever went out with the goods — exactly one of the two, per
    // delivery_assignment_type. contact_person only exists on a company.
    const assignee = order.delivery_representative
        ? { ...order.delivery_representative, contact_person: null as string | null }
        : order.shipping_company;
    const assignment = order.delivery_assignments[0] ?? null;

    // What the courier came back short by, if anything. The goods are
    // already delivered and the stock deducted — only the money is open,
    // so this order keeps showing up here until it settles.
    const payment = order.payments[order.payments.length - 1] ?? null;
    const outstanding =
        order.payment_status === 'partially_collected' && payment !== null
            ? {
                  due: Number(payment.amount),
                  collected: Number(payment.collected_amount ?? 0),
                  remaining: round2(Number(payment.amount) - Number(payment.collected_amount ?? 0)),
              }
            : null;
    const [balanceAmount, setBalanceAmount] = useState(outstanding ? String(outstanding.remaining) : '');

    // What a partial return is worth, so the amount field isn't mental
    // arithmetic at the counter. A suggestion only: confirmPartiallyReturned
    // stores the amount Accounting actually types, it does not recompute
    // one — the courier may have come back with a different figure.
    const keptValue = order.items.reduce(
        (sum, item) => sum + Number(item.unit_price) * (keptQuantities[item.id] ?? 0),
        0,
    );
    const returnedValue = Number(order.subtotal) - keptValue;
    // Pro-rata: a whole-order discount belongs to the goods, so only the
    // share sitting on kept lines survives the return.
    const discountShare =
        Number(order.subtotal) > 0 ? (Number(order.discount_amount) * keptValue) / Number(order.subtotal) : 0;
    const suggestedTotal = Math.max(0, round2(keptValue - discountShare + Number(order.shipping_amount)));

    async function confirmDelivered() {
        if (!treasuryId) return;
        if (
            !(await confirmAction({
                title: t('admin.confirmDeliveredQ'),
                text: t('admin.confirmDeliveredHint'),
            }))
        )
            return;
        router.post(
            route('admin.accounting.delivered', order.id),
            {
                treasury_id: treasuryId,
                collected_method: collectedMethod,
                collected_amount: collectedAmount || undefined,
            },
            FRESH,
        );
    }

    async function collectBalance() {
        if (!treasuryId || !balanceAmount) return;
        if (!(await confirmAction({ title: t('admin.recordCollectionQ', { amount: balanceAmount }) }))) return;
        router.post(
            route('admin.accounting.collect', order.id),
            {
                treasury_id: treasuryId,
                collected_method: collectedMethod,
                amount: balanceAmount,
            },
            FRESH,
        );
    }

    async function confirmReturned() {
        if (
            !(await confirmAction({
                title: t('admin.confirmReturnedQ'),
                text: t('admin.confirmReturnedHint'),
            }))
        )
            return;
        router.post(route('admin.accounting.returned', order.id), {}, FRESH);
    }

    async function confirmPartial() {
        if (!treasuryId) return;
        if (
            !(await confirmAction({
                title: t('admin.confirmPartiallyReturnedQ'),
                text: t('admin.confirmPartiallyReturnedHint'),
            }))
        )
            return;
        router.post(
            route('admin.accounting.partially-returned', order.id),
            {
                treasury_id: treasuryId,
                collected_method: collectedMethod,
                collected_amount: collectedAmount,
                kept_quantities: keptQuantities,
            },
            FRESH,
        );
    }

    return (
        <AdminLayout
            title={t('admin.accountingForOrder', { number: order.order_number })}
            breadcrumbs={[{ label: t('admin.accountingDeliveryConfirmation'), href: route('admin.accounting.index') }]}
            actions={
                <Link
                    href={route('admin.orders.invoice', order.id)}
                    target="_blank"
                    className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                >
                    <i className="bx bx-printer" />
                    {t('admin.invoice')}
                </Link>
            }
        >
            <Head title={t('admin.orderNumber', { number: order.order_number })} />

            <div className="row">
                <div className="col-xl-7">
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
                                        <th>{t('admin.subTotal')}</th>
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
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(item.unit_price) * item.quantity)}
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
                            <h4 className="card-title">{t('admin.deliveryDetails')}</h4>
                        </div>
                        <div className="card-body">
                            {assignee === null ? (
                                <p className="text-muted mb-0">{t('admin.notAssignedYet')}</p>
                            ) : (
                                <ul className="list-unstyled mb-0 fs-13">
                                    <DetailRow label={t('admin.assignedTo')} value={assignee.name} />
                                    <DetailRow
                                        label={t('admin.type')}
                                        value={t(`assignmentType.${order.delivery_assignment_type}`)}
                                    />
                                    <DetailRow label={t('admin.contactNumber')} value={assignee.phone} ltr />
                                    {assignee.contact_person && (
                                        <DetailRow label={t('admin.contactPerson')} value={assignee.contact_person} />
                                    )}
                                    {assignment && (
                                        <>
                                            <DetailRow
                                                label={t('admin.assignedAt')}
                                                value={dateTime(assignment.assigned_at)}
                                                ltr
                                            />
                                            <DetailRow
                                                label={t('admin.by')}
                                                value={assignment.assigned_by?.full_name ?? '—'}
                                            />
                                        </>
                                    )}
                                </ul>
                            )}
                        </div>
                    </div>

                    <OrderSummaryCard order={order} />
                </div>

                <div className="col-xl-5">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.customerDetails')}</h4>
                        </div>
                        <div className="card-body">
                            <p className="mb-1 fw-medium">{order.customer.name}</p>
                            <p className="mb-0 text-muted">{order.customer.phone}</p>
                        </div>
                    </div>

                    <ShippingAddressCard order={order} />

                    {outstanding !== null && (
                        <div className="card border-warning">
                            <div className="card-header d-flex justify-content-between align-items-center">
                                <h4 className="card-title">{t('admin.outstandingBalance')}</h4>
                                <StatusBadge status={order.payment_status} />
                            </div>
                            <div className="card-body">
                                <table className="table table-sm mb-3">
                                    <tbody>
                                        <PreviewRow label={t('admin.amountDue')} value={price(outstanding.due)} />
                                        <PreviewRow
                                            label={t('admin.collectedSoFar')}
                                            value={price(outstanding.collected)}
                                            muted
                                        />
                                    </tbody>
                                    <tfoot className="border-top">
                                        <tr>
                                            <td className="px-0 fw-semibold text-danger">{t('admin.stillOwed')}</td>
                                            <td className="text-end px-0 fw-semibold text-danger">
                                                <span dir="ltr">{price(outstanding.remaining)}</span>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>

                                <h5 className="fs-13 text-muted mb-1">{t('admin.paymentCollections')}</h5>
                                <div className="mb-3">
                                    <PaymentInstalments instalments={payment?.transactions ?? []} />
                                </div>

                                <TreasuryFields
                                    treasuries={treasuries}
                                    treasuryId={treasuryId}
                                    setTreasuryId={setTreasuryId}
                                    collectedMethod={collectedMethod}
                                    setCollectedMethod={setCollectedMethod}
                                    collectedAmount={balanceAmount}
                                    setCollectedAmount={setBalanceAmount}
                                />
                                <button type="button" className="btn btn-primary w-100 mt-2" onClick={collectBalance}>
                                    {t('admin.recordCollection')}
                                </button>
                            </div>
                        </div>
                    )}

                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <h4 className="card-title">{t('admin.deliveryResult')}</h4>
                            <StatusBadge status={order.status} />
                        </div>
                        <div className="card-body">
                            {!canAct ? (
                                <p className="text-muted mb-0">{t('admin.orderNotOutForDelivery')}</p>
                            ) : (
                                <Tabs defaultActiveKey="delivered" className="nav-tabs-custom mb-3">
                                    <Tab eventKey="delivered" title={t('admin.delivered')}>
                                        <TreasuryFields
                                            treasuries={treasuries}
                                            treasuryId={treasuryId}
                                            setTreasuryId={setTreasuryId}
                                            collectedMethod={collectedMethod}
                                            setCollectedMethod={setCollectedMethod}
                                            collectedAmount={collectedAmount}
                                            setCollectedAmount={setCollectedAmount}
                                        />
                                        <button
                                            type="button"
                                            className="btn btn-success w-100 mt-2"
                                            onClick={confirmDelivered}
                                        >
                                            {t('admin.confirmDelivered')}
                                        </button>
                                    </Tab>
                                    <Tab eventKey="returned" title={t('admin.returned')}>
                                        <p className="text-muted fs-13">{t('admin.fullyRefusedExplainer')}</p>
                                        <button
                                            type="button"
                                            className="btn btn-danger w-100"
                                            onClick={confirmReturned}
                                        >
                                            {t('admin.confirmReturned')}
                                        </button>
                                    </Tab>
                                    <Tab eventKey="partial" title={t('admin.partiallyReturned')}>
                                        <p className="text-muted fs-13">{t('admin.setHowManyOfEachItem')}</p>
                                        {order.items.map((item) => (
                                            <div key={item.id} className="mb-2">
                                                <label className="form-label fs-13 mb-1">
                                                    {item.product_name_snapshot} (of {item.quantity})
                                                </label>
                                                <input
                                                    type="number"
                                                    className="form-control"
                                                    min={0}
                                                    max={item.quantity}
                                                    value={keptQuantities[item.id]}
                                                    onChange={(e) =>
                                                        setKeptQuantities({
                                                            ...keptQuantities,
                                                            [item.id]: Number(e.target.value),
                                                        })
                                                    }
                                                />
                                            </div>
                                        ))}
                                        <table className="table table-sm mt-3 mb-2">
                                            <tbody>
                                                <PreviewRow
                                                    label={t('admin.keptItems')}
                                                    value={price(round2(keptValue))}
                                                />
                                                <PreviewRow
                                                    label={t('admin.returnedItems')}
                                                    value={`-${price(round2(returnedValue))}`}
                                                    muted
                                                />
                                                {Number(order.discount_amount) > 0 && (
                                                    <PreviewRow
                                                        label={t('admin.discountProRata')}
                                                        value={`-${price(round2(discountShare))}`}
                                                        muted
                                                    />
                                                )}
                                                <PreviewRow
                                                    label={t('admin.deliveryCharge')}
                                                    value={price(Number(order.shipping_amount))}
                                                />
                                            </tbody>
                                            <tfoot className="border-top">
                                                <tr>
                                                    <td className="px-0 fw-semibold text-dark">
                                                        {t('admin.suggestedCollection')}
                                                    </td>
                                                    <td className="text-end px-0 fw-semibold text-dark">
                                                        <span dir="ltr">{price(suggestedTotal)}</span>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-soft-secondary w-100 mb-3"
                                            disabled={collectedAmount === String(suggestedTotal)}
                                            onClick={() => setCollectedAmount(String(suggestedTotal))}
                                        >
                                            {t('admin.useThisAmount')}
                                        </button>
                                        <TreasuryFields
                                            treasuries={treasuries}
                                            treasuryId={treasuryId}
                                            setTreasuryId={setTreasuryId}
                                            collectedMethod={collectedMethod}
                                            setCollectedMethod={setCollectedMethod}
                                            collectedAmount={collectedAmount}
                                            setCollectedAmount={setCollectedAmount}
                                        />
                                        <button
                                            type="button"
                                            className="btn btn-warning w-100 mt-2"
                                            onClick={confirmPartial}
                                        >
                                            {t('admin.confirmPartiallyReturned')}
                                        </button>
                                    </Tab>
                                </Tabs>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

function TreasuryFields({
    treasuries,
    treasuryId,
    setTreasuryId,
    collectedMethod,
    setCollectedMethod,
    collectedAmount,
    setCollectedAmount,
}: {
    treasuries: Treasury[];
    treasuryId: number | '';
    setTreasuryId: (id: number) => void;
    collectedMethod: string;
    setCollectedMethod: (m: string) => void;
    collectedAmount: string;
    setCollectedAmount: (a: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <>
            <div className="mb-2">
                <label className="form-label fs-13 mb-1">{t('admin.treasury')}</label>
                <select
                    className="form-control"
                    value={treasuryId}
                    onChange={(e) => setTreasuryId(Number(e.target.value))}
                >
                    {treasuries.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.name} ({t(`treasury.type.${account.type}`)})
                        </option>
                    ))}
                </select>
            </div>
            <div className="mb-2">
                <label className="form-label fs-13 mb-1">{t('admin.collectionMethod')}</label>
                <select
                    className="form-control"
                    value={collectedMethod}
                    onChange={(e) => setCollectedMethod(e.target.value)}
                >
                    {COLLECTED_METHODS.map((m) => (
                        <option key={m} value={m}>
                            {t(`collectedMethod.${m}`)}
                        </option>
                    ))}
                </select>
            </div>
            <div className="mb-2">
                <label className="form-label fs-13 mb-1">{t('admin.amountCollected')}</label>
                <input
                    type="number"
                    step="0.01"
                    className="form-control"
                    value={collectedAmount}
                    onChange={(e) => setCollectedAmount(e.target.value)}
                />
            </div>
        </>
    );
}

/** Money rounding, so a pro-rata share can't show 15 decimal places. */
function round2(value: number): number {
    return Math.round(value * 100) / 100;
}

/** One line of the partial-return preview. */
function PreviewRow({ label, value, muted = false }: { label: string; value: string; muted?: boolean }) {
    return (
        <tr>
            <td className="px-0">{label}</td>
            <td className={`text-end px-0 fw-medium ${muted ? 'text-muted' : 'text-dark'}`}>
                <span dir="ltr">{value}</span>
            </td>
        </tr>
    );
}

/** One labelled line of the delivery card. */
function DetailRow({ label, value, ltr = false }: { label: string; value: string; ltr?: boolean }) {
    return (
        <li className="d-flex justify-content-between gap-2">
            <span className="text-muted">{label}</span>
            {ltr ? <span dir="ltr">{value}</span> : <span className="text-dark">{value}</span>}
        </li>
    );
}
