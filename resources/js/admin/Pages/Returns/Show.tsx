import { Head, Link, router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import OrderSummaryCard from '../../Components/OrderSummaryCard';
import ShippingAddressCard from '../../Components/ShippingAddressCard';
import StatusBadge from '../../Components/StatusBadge';
import VariantPicker from '../../Components/VariantPicker';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { usePermissions } from '../../Hooks/usePermissions';
import { useTranslation } from '../../lib/useTranslation';

interface ReturnItem {
    id: number;
    quantity: number;
    order_item: {
        id: number;
        unit_price: string;
        // The name and SKU as sold. order_items snapshots both at
        // checkout, so they stay correct — and stay *renderable* —
        // after a product is soft-deleted, which nulls the relation.
        product_name_snapshot: string;
        variant_sku_snapshot: string;
        product_variant: { sku: string; product: { name: string } | null } | null;
    };
}

interface GeoName {
    id: number;
    name: string;
}

interface OrderItemFull {
    id: number;
    quantity: number;
    unit_price: string;
    subtotal: string;
    product_name_snapshot: string;
    variant_sku_snapshot: string;
}

interface ReturnDetail {
    id: number;
    status: string;
    stage: string;
    return_shipping_fee: string | null;
    customer_accepted_return_shipping_fee_at: string | null;
    checked_at: string | null;
    checking_notes: string | null;
    checked_by: { id: number; full_name: string } | null;
    delivery_representative: { id: number; name: string } | null;
    shipping_company: { id: number; name: string } | null;
    customer_notes: string | null;
    order: {
        id: number;
        order_number: number;
        status: string;
        payment_status: string;
        subtotal: string;
        discount_amount: string;
        shipping_amount: string;
        total: string;
        shipping_recipient_name: string;
        shipping_phone: string;
        shipping_address_line: string;
        customer: { name: string; phone: string; email: string | null };
        items: OrderItemFull[];
        shipping_governorate: GeoName | null;
        shipping_city: GeoName | null;
        shipping_district: GeoName | null;
        shipping_area: GeoName | null;
        shipping_company: { id: number; name: string } | null;
        delivery_representative: { id: number; name: string; phone: string } | null;
    };
    items: ReturnItem[];
    reason: { name: string } | null;
    refund: { id: number; net_amount: string; method: string; reference_number: string } | null;
}

interface Option {
    id: number;
    name: string;
}

interface Treasury extends Option {
    type: string;
}

const REFUND_METHODS = ['bank_transfer', 'wallet', 'cash'];

// Each refund method posts against the treasury of the same type —
// picking cash jumps the treasury to the cash till, and so on.
const METHOD_TREASURY_TYPE: Record<string, string> = {
    bank_transfer: 'bank',
    wallet: 'wallet',
    cash: 'cash',
};

// Ported from Admin Template/order-detail.html's Product table + summary
// card conventions.
export default function ReturnsShow({
    return: ret,
    warehouse,
    treasuries,
    representatives,
    shippingCompanies,
}: {
    return: ReturnDetail;
    warehouse: { id: number; name: string } | null;
    treasuries: Treasury[];
    representatives: Option[];
    shippingCompanies: Option[];
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();

    // What the customer is sending back, priced at the as-sold unit price —
    // so Accounting can compare it against the order grand total below.
    const returnedTotal = ret.items.reduce((sum, item) => sum + Number(item.order_item.unit_price) * item.quantity, 0);
    const returnedIds = new Set(ret.items.map((item) => item.order_item.id));
    const [shippingFee, setShippingFee] = useState('0');
    const [treasuryId, setTreasuryId] = useState<number | ''>(treasuries[0]?.id ?? '');
    const [method, setMethod] = useState('bank_transfer');
    const [reference, setReference] = useState('');
    const [checkNotes, setCheckNotes] = useState('');
    const [checkError, setCheckError] = useState<string | null>(null);
    // Resolved by the picker once a product and every colour/size it has
    // are chosen; null until then.
    const [replacementVariant, setReplacementVariant] = useState<number | null>(null);
    const [pickupCourier, setPickupCourier] = useState<string>('');

    function handleMethodChange(next: string) {
        setMethod(next);
        const match = treasuries.find((account) => account.type === METHOD_TREASURY_TYPE[next]);
        if (match) setTreasuryId(match.id);
    }

    // The return moves through five steps in a fixed order, and the server
    // enforces the same order: fee → call (its Confirm is the approval) →
    // courier → receive → refund OR replacement. Each step's state is read
    // straight off the return, so the card only ever offers the next one.
    const status = ret.status;
    const feeNeeded = ret.stage === 'post_delivery';
    const feeAgreed = !feeNeeded || ret.customer_accepted_return_shipping_fee_at !== null;
    const cancelled = status === 'rejected';
    const received = ['inspected', 'refunded', 'completed'].includes(status);
    const approved = status === 'approved' || received;
    const courier = ret.delivery_representative?.name ?? ret.shipping_company?.name ?? null;
    const [changingCourier, setChangingCourier] = useState(false);

    const steps: Record<1 | 2 | 3 | 4 | 5, StepState> = {
        1: feeAgreed ? 'done' : status === 'requested' ? 'current' : 'waiting',
        2: cancelled ? 'cancelled' : approved ? 'done' : status === 'requested' ? 'current' : 'waiting',
        3: cancelled
            ? 'cancelled'
            : received || (status === 'approved' && courier !== null)
              ? 'done'
              : status === 'approved'
                ? 'current'
                : 'waiting',
        4: cancelled
            ? 'cancelled'
            : received
              ? 'done'
              : status === 'approved' && courier !== null
                ? 'current'
                : 'waiting',
        5: cancelled ? 'cancelled' : status === 'inspected' ? 'current' : received ? 'done' : 'waiting',
    };

    function assignPickup() {
        if (pickupCourier === '') return;
        const [type, id] = pickupCourier.split(':');
        router.post(
            route('admin.returns.assign-pickup', ret.id),
            { assignment_type: type, assignee_id: Number(id) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPickupCourier('');
                    setChangingCourier(false);
                },
            },
        );
    }

    async function sendReplacement() {
        if (replacementVariant === null) return;
        if (!(await confirmAction({ title: t('admin.createReplacementQ') }))) return;

        router.post(route('admin.returns.replace', ret.id), {
            items: [{ product_variant_id: replacementVariant, quantity: 1 }],
        });
    }

    async function check(outcome: 'confirm' | 'reschedule' | 'cancel') {
        if (outcome === 'cancel' && checkNotes.trim() === '') {
            setCheckError(t('admin.cancelReasonRequired'));
            return;
        }

        const confirmed = await confirmAction({
            title: t(`admin.checkReturn_${outcome}_q`),
            danger: outcome === 'cancel',
        });
        if (!confirmed) return;

        setCheckError(null);
        router.post(
            route('admin.returns.check', ret.id),
            { outcome, notes: checkNotes || null },
            { preserveScroll: true },
        );
    }

    async function acceptFee() {
        if (!(await confirmAction({ title: t('admin.recordAcceptedReturnFee') }))) return;
        router.post(route('admin.returns.accept-shipping-fee', ret.id), { return_shipping_fee: shippingFee });
    }

    async function receive() {
        if (
            !(await confirmAction({
                title: t('admin.confirmItemsReceived'),
                text: t('admin.sellableItemsRestocked'),
            }))
        )
            return;
        // No warehouse_id — the server always restocks into the main
        // warehouse, which is shown read-only below.
        router.post(route('admin.returns.receive', ret.id));
    }

    async function refund() {
        if (!treasuryId || !reference) return;
        if (!(await confirmAction({ title: t('admin.recordThisRefund') }))) return;
        router.post(route('admin.returns.refund', ret.id), {
            treasury_id: treasuryId,
            method,
            reference_number: reference,
        });
    }

    return (
        <AdminLayout
            title={t('admin.returnForOrder', { number: ret.order.order_number })}
            breadcrumbs={[{ label: t('admin.returnsRefunds'), href: route('admin.returns.index') }]}
        >
            <Head title={t('admin.returnForOrder', { number: ret.order.order_number })} />

            <div className="row">
                <div className="col-xl-7">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.returnedItems')}</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.product')}</th>
                                        <th>SKU</th>
                                        <th>{t('admin.qty')}</th>
                                        <th>{t('admin.unitPrice')}</th>
                                        <th>{t('admin.total')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ret.items.map((item) => (
                                        <tr key={item.id}>
                                            <td>{item.order_item.product_name_snapshot}</td>
                                            <td className="text-muted">{item.order_item.variant_sku_snapshot}</td>
                                            <td>{item.quantity}</td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(item.order_item.unit_price))}
                                                </span>
                                            </td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(item.order_item.unit_price) * item.quantity)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot className="border-top">
                                    <tr>
                                        <td colSpan={4} className="fw-semibold text-dark">
                                            {t('admin.total')}
                                        </td>
                                        <td className="fw-semibold text-dark">
                                            <span dir="ltr" className="text-nowrap">
                                                {price(returnedTotal)}
                                            </span>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title">{t('admin.orderTitle', { number: ret.order.order_number })}</h4>
                            <div className="d-flex gap-2">
                                <StatusBadge status={ret.order.status} />
                                <StatusBadge status={ret.order.payment_status} />
                            </div>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.product')}</th>
                                        <th>SKU</th>
                                        <th>{t('admin.qty')}</th>
                                        <th>{t('admin.unitPrice')}</th>
                                        <th>{t('admin.total')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ret.order.items.map((item) => (
                                        <tr key={item.id} className={returnedIds.has(item.id) ? 'table-warning' : ''}>
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
                                                    {price(Number(item.subtotal))}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <OrderSummaryCard order={ret.order} />

                    <ShippingAddressCard order={ret.order} />

                    {ret.refund && (
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.refund')}</h4>
                            </div>
                            <div className="card-body">
                                <p className="mb-1">
                                    {t('admin.netAmount')}: <strong>{ret.refund.net_amount}</strong>
                                </p>
                                <p className="mb-0 text-muted">
                                    {t(`refundMethod.${ret.refund.method}`)} — {t('admin.ref')}{' '}
                                    {ret.refund.reference_number}
                                </p>
                            </div>
                        </div>
                    )}
                </div>

                <div className="col-xl-5">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.returnDetails')}</h4>
                        </div>
                        <div className="card-body">
                            <p className="mb-1 fw-medium">
                                {t('admin.orderForCustomer', {
                                    number: ret.order.order_number,
                                    name: ret.order.customer.name,
                                })}
                            </p>
                            <p className="mb-1 text-muted">{ret.order.customer.phone}</p>
                            <p className="mb-1">
                                <Link href={route('admin.orders.show', ret.order.id)} className="link-primary fs-13">
                                    {t('admin.view')} — {t('admin.orderTitle', { number: ret.order.order_number })}
                                </Link>
                            </p>
                            <p className="mb-1">
                                {t('admin.assignedTo')}:{' '}
                                <span className="text-dark">
                                    {ret.order.delivery_representative?.name ??
                                        ret.order.shipping_company?.name ??
                                        t('admin.none')}
                                </span>
                            </p>
                            <p className="mb-1">
                                {t('admin.stage')}:{' '}
                                <span className="badge bg-light text-dark border px-2 py-1">
                                    {t(`returnStage.${ret.stage}`)}
                                </span>
                            </p>
                            <p className="mb-1">
                                {t('admin.reason')}: {ret.reason?.name}
                            </p>
                            {ret.customer_notes && (
                                <p className="mb-1 text-muted">&ldquo;{ret.customer_notes}&rdquo;</p>
                            )}
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center">
                            <h4 className="card-title">{t('admin.actions')}</h4>
                            <StatusBadge status={ret.status} />
                        </div>
                        <div className="card-body">
                            <ol className="list-unstyled mb-0">
                                {/* 1 — what the customer pays to send it back.
                                    Usually agreed on the call itself, so the
                                    caller may record it too. */}
                                <Step n={1} title={t('admin.returnStep1')} state={steps[1]}>
                                    {steps[1] === 'done' && (
                                        <p className="text-muted fs-13 mb-0">
                                            {feeNeeded
                                                ? t('admin.returnFeeAgreed', {
                                                      amount: price(Number(ret.return_shipping_fee ?? 0)),
                                                  })
                                                : t('admin.returnFeeNotNeeded')}
                                        </p>
                                    )}
                                    {steps[1] === 'current' &&
                                        (can('returns.create') || can('returns.check') ? (
                                            <>
                                                <p className="fs-13 text-muted">
                                                    {t('admin.returnShippingFeeExplainer')}
                                                </p>
                                                <div className="d-flex gap-2">
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        className="form-control"
                                                        aria-label={t('admin.returnShippingFee')}
                                                        value={shippingFee}
                                                        onChange={(e) => setShippingFee(e.target.value)}
                                                    />
                                                    <button
                                                        type="button"
                                                        className="btn btn-primary text-nowrap"
                                                        onClick={acceptFee}
                                                    >
                                                        {t('admin.recordAcceptedFee')}
                                                    </button>
                                                </div>
                                            </>
                                        ) : (
                                            <NoAccess />
                                        ))}
                                </Step>

                                {/* 2 — Checking's call. Confirm is the one and
                                    only way a return is approved. */}
                                <Step n={2} title={t('admin.returnStep2')} state={steps[2]}>
                                    {ret.checked_at !== null && (
                                        <div className="fs-13 mb-2">
                                            <div className="fw-semibold">
                                                {t('admin.checkedBy', {
                                                    name: ret.checked_by?.full_name ?? '—',
                                                    at: dateTime(ret.checked_at),
                                                })}
                                            </div>
                                            {ret.checking_notes && (
                                                <div className="text-muted">{ret.checking_notes}</div>
                                            )}
                                        </div>
                                    )}
                                    {steps[2] === 'cancelled' && (
                                        <p className="text-danger fs-13 mb-0">{t('admin.returnCancelledOnCall')}</p>
                                    )}
                                    {steps[2] === 'current' &&
                                        (can('returns.check') ? (
                                            <>
                                                <p className="text-muted fs-13 mb-2">
                                                    {t('admin.checkWithCustomerHint')}
                                                </p>
                                                <textarea
                                                    className="form-control mb-2"
                                                    rows={2}
                                                    placeholder={t('admin.callNotesPlaceholder')}
                                                    value={checkNotes}
                                                    onChange={(event) => {
                                                        setCheckNotes(event.target.value);
                                                        setCheckError(null);
                                                    }}
                                                />
                                                {checkError !== null && (
                                                    <div className="text-danger fs-13 mb-2">{checkError}</div>
                                                )}
                                                {/* Reschedule and Cancel never wait for the
                                                    fee — the customer may refuse to pay it. */}
                                                {!feeAgreed && (
                                                    <p className="text-warning fs-13 mb-2">
                                                        {t('admin.returnConfirmNeedsFee')}
                                                    </p>
                                                )}
                                                <div className="d-flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        className="btn btn-success btn-sm"
                                                        disabled={!feeAgreed}
                                                        onClick={() => check('confirm')}
                                                    >
                                                        {t('admin.checkReturn_confirm')}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-soft-secondary btn-sm"
                                                        onClick={() => check('reschedule')}
                                                    >
                                                        {t('admin.checkReturn_reschedule')}
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="btn btn-soft-danger btn-sm"
                                                        onClick={() => check('cancel')}
                                                    >
                                                        {t('admin.checkReturn_cancel')}
                                                    </button>
                                                </div>
                                            </>
                                        ) : (
                                            <NoAccess />
                                        ))}
                                </Step>

                                {/* 3 — who goes and gets it. Can still be
                                    changed until the goods arrive. */}
                                <Step n={3} title={t('admin.returnStep3')} state={steps[3]}>
                                    {steps[3] === 'done' && courier !== null && (
                                        <p className="text-muted fs-13 mb-0">
                                            {courier}
                                            {status === 'approved' &&
                                                can('returns.assign_pickup') &&
                                                !changingCourier && (
                                                    <button
                                                        type="button"
                                                        className="btn btn-link btn-sm p-0 ms-2 align-baseline"
                                                        onClick={() => setChangingCourier(true)}
                                                    >
                                                        {t('admin.changeCourier')}
                                                    </button>
                                                )}
                                        </p>
                                    )}
                                    {(steps[3] === 'current' || changingCourier) &&
                                        (can('returns.assign_pickup') ? (
                                            <div className="d-flex gap-2 mt-1">
                                                <select
                                                    className="form-select"
                                                    value={pickupCourier}
                                                    onChange={(event) => setPickupCourier(event.target.value)}
                                                >
                                                    <option value="">{t('admin.chooseCourier')}</option>
                                                    {representatives.map((rep) => (
                                                        <option key={`r${rep.id}`} value={`representative:${rep.id}`}>
                                                            {rep.name}
                                                        </option>
                                                    ))}
                                                    {shippingCompanies.map((company) => (
                                                        <option
                                                            key={`c${company.id}`}
                                                            value={`shipping_company:${company.id}`}
                                                        >
                                                            {company.name}
                                                        </option>
                                                    ))}
                                                </select>
                                                <button
                                                    type="button"
                                                    className="btn btn-primary"
                                                    disabled={pickupCourier === ''}
                                                    onClick={assignPickup}
                                                >
                                                    {t('admin.send')}
                                                </button>
                                            </div>
                                        ) : (
                                            <NoAccess />
                                        ))}
                                </Step>

                                {/* 4 — the goods are back; restock into the
                                    main warehouse (never selectable). */}
                                <Step n={4} title={t('admin.returnStep4')} state={steps[4]}>
                                    {steps[4] === 'done' && (
                                        <p className="text-muted fs-13 mb-0">{t('admin.returnReceivedDone')}</p>
                                    )}
                                    {steps[4] === 'current' &&
                                        (can('returns.receive') ? (
                                            <>
                                                <p className="text-muted fs-13 mb-2">
                                                    {t('admin.receivingWarehouse')}: {warehouse?.name ?? '—'}
                                                </p>
                                                <button
                                                    type="button"
                                                    className="btn btn-primary"
                                                    onClick={receive}
                                                    disabled={!warehouse}
                                                >
                                                    {t('admin.confirmReceivedAndRestock')}
                                                </button>
                                            </>
                                        ) : (
                                            <NoAccess />
                                        ))}
                                </Step>

                                {/* 5 — the two ways a return ends. Pick one. */}
                                <Step n={5} title={t('admin.returnStep5')} state={steps[5]} last>
                                    {status === 'refunded' && (
                                        <p className="text-muted fs-13 mb-0">
                                            {ret.refund
                                                ? t('admin.returnRefundedDone', {
                                                      amount: price(Number(ret.refund.net_amount)),
                                                      method: t(`refundMethod.${ret.refund.method}`),
                                                  })
                                                : t('admin.returnRefundedDone', { amount: '—', method: '—' })}
                                        </p>
                                    )}
                                    {status === 'completed' && (
                                        <p className="text-muted fs-13 mb-0">{t('admin.returnReplacedDone')}</p>
                                    )}
                                    {steps[5] === 'current' && !can('returns.refund') && !can('returns.replace') && (
                                        <NoAccess />
                                    )}
                                    {steps[5] === 'current' && can('returns.refund') && (
                                        <div className="border rounded p-3">
                                            <div className="fw-semibold mb-2">{t('admin.recordRefund')}</div>
                                            <div className="mb-2">
                                                <label className="form-label fs-13">{t('admin.method')}</label>
                                                <select
                                                    className="form-control"
                                                    value={method}
                                                    onChange={(e) => handleMethodChange(e.target.value)}
                                                >
                                                    {REFUND_METHODS.map((m) => (
                                                        <option key={m} value={m}>
                                                            {t(`refundMethod.${m}`)}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="mb-2">
                                                <label className="form-label fs-13">{t('admin.treasury')}</label>
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
                                                <label className="form-label fs-13">{t('admin.referenceNumber')}</label>
                                                <input
                                                    className="form-control"
                                                    value={reference}
                                                    onChange={(e) => setReference(e.target.value)}
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                className="btn btn-success w-100"
                                                onClick={refund}
                                                disabled={!reference}
                                            >
                                                {t('admin.recordRefund')}
                                            </button>
                                        </div>
                                    )}
                                    {steps[5] === 'current' && can('returns.refund') && can('returns.replace') && (
                                        <div className="text-center text-muted fs-13 my-2">{t('admin.or')}</div>
                                    )}
                                    {steps[5] === 'current' && can('returns.replace') && (
                                        <div className="border rounded p-3">
                                            <div className="fw-semibold">{t('admin.sendReplacement')}</div>
                                            <p className="text-muted fs-13 mb-2">{t('admin.sendReplacementHint')}</p>
                                            <div className="mb-2">
                                                <VariantPicker
                                                    searchUrl={route('admin.returns.product-search')}
                                                    onResolve={(variant) => setReplacementVariant(variant?.id ?? null)}
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                className="btn btn-primary w-100"
                                                disabled={replacementVariant === null}
                                                onClick={sendReplacement}
                                            >
                                                {t('admin.createReplacementOrder')}
                                            </button>
                                        </div>
                                    )}
                                </Step>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

type StepState = 'done' | 'current' | 'waiting' | 'cancelled';

// One numbered row of the return's progress. Done steps show a tick and
// their summary, the current step shows its form, later steps are greyed
// and say which step they are waiting for.
function Step({
    n,
    title,
    state,
    last = false,
    children,
}: {
    n: number;
    title: string;
    state: StepState;
    last?: boolean;
    children?: ReactNode;
}) {
    const { t } = useTranslation();

    const marker =
        state === 'done'
            ? 'bg-success text-white'
            : state === 'current'
              ? 'bg-primary text-white'
              : 'bg-light text-muted border';

    return (
        <li className={`d-flex gap-3 ${last ? '' : 'pb-3 mb-3 border-bottom'}`}>
            <span
                className={`rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold fs-13 ${marker}`}
                style={{ width: 28, height: 28 }}
                aria-hidden="true"
            >
                {state === 'done' ? <i className="bx bx-check" /> : n}
            </span>
            <div className="flex-grow-1" style={{ minWidth: 0 }}>
                <div className={`fw-semibold ${state === 'waiting' || state === 'cancelled' ? 'text-muted' : ''}`}>
                    {title}
                </div>
                {state === 'waiting' && (
                    <p className="text-muted fs-13 mb-0">{t('admin.returnStepWaits', { step: n - 1 })}</p>
                )}
                {state !== 'waiting' && children}
            </div>
        </li>
    );
}

function NoAccess() {
    const { t } = useTranslation();

    return <p className="text-muted fs-13 mb-0">{t('admin.returnStepNoAccess')}</p>;
}
