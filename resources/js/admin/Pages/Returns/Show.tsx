import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import OrderSummaryCard from '../../Components/OrderSummaryCard';
import ShippingAddressCard from '../../Components/ShippingAddressCard';
import StatusBadge from '../../Components/StatusBadge';
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
}: {
    return: ReturnDetail;
    warehouse: { id: number; name: string } | null;
    treasuries: Treasury[];
}) {
    const { t, price } = useTranslation();
    const { can } = usePermissions();

    // What the customer is sending back, priced at the as-sold unit price —
    // so Accounting can compare it against the order grand total below.
    const returnedTotal = ret.items.reduce((sum, item) => sum + Number(item.order_item.unit_price) * item.quantity, 0);
    const returnedIds = new Set(ret.items.map((item) => item.order_item.id));
    const [shippingFee, setShippingFee] = useState('0');
    const [treasuryId, setTreasuryId] = useState<number | ''>(treasuries[0]?.id ?? '');
    const [method, setMethod] = useState('bank_transfer');
    const [reference, setReference] = useState('');

    function handleMethodChange(next: string) {
        setMethod(next);
        const match = treasuries.find((account) => account.type === METHOD_TREASURY_TYPE[next]);
        if (match) setTreasuryId(match.id);
    }

    const needsShippingFeeConsent =
        ret.stage === 'post_delivery' && ret.status === 'requested' && !ret.customer_accepted_return_shipping_fee_at;
    const canApprove =
        ret.status === 'requested' &&
        (ret.stage !== 'post_delivery' || ret.customer_accepted_return_shipping_fee_at) &&
        can('returns.approve');
    const canReceive = ret.status === 'approved' && can('returns.receive');
    const canRefund = ret.status === 'inspected' && can('returns.refund');

    async function acceptFee() {
        if (!(await confirmAction({ title: t('admin.recordAcceptedReturnFee') }))) return;
        router.post(route('admin.returns.accept-shipping-fee', ret.id), { return_shipping_fee: shippingFee });
    }

    async function approve() {
        if (!(await confirmAction({ title: t('admin.approveThisReturn') }))) return;
        router.post(route('admin.returns.approve', ret.id));
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
                            <h4 className="card-title">
                                {t('admin.orderTitle', { number: ret.order.order_number })}
                            </h4>
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
                                        <tr
                                            key={item.id}
                                            className={returnedIds.has(item.id) ? 'table-warning' : ''}
                                        >
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
                                <Link
                                    href={route('admin.orders.show', ret.order.id)}
                                    className="link-primary fs-13"
                                >
                                    {t('admin.view')} —{' '}
                                    {t('admin.orderTitle', { number: ret.order.order_number })}
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
                            {needsShippingFeeConsent && (
                                <>
                                    <p className="fs-13 text-muted">{t('admin.returnShippingFeeExplainer')}</p>
                                    <div className="mb-2">
                                        <label className="form-label fs-13">{t('admin.returnShippingFee')}</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            className="form-control"
                                            value={shippingFee}
                                            onChange={(e) => setShippingFee(e.target.value)}
                                        />
                                    </div>
                                    <button type="button" className="btn btn-primary btn-sm" onClick={acceptFee}>
                                        {t('admin.recordAcceptedFee')}
                                    </button>
                                </>
                            )}

                            {canApprove && (
                                <button type="button" className="btn btn-success" onClick={approve}>
                                    {t('admin.approveReturn')}
                                </button>
                            )}

                            {canReceive && (
                                <>
                                    {/* Not selectable — received items always
                                        restock into the main warehouse. */}
                                    <div className="mb-2">
                                        <label className="form-label fs-13">{t('admin.receivingWarehouse')}</label>
                                        <input
                                            className="form-control"
                                            value={warehouse?.name ?? ''}
                                            readOnly
                                            disabled
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        className="btn btn-primary"
                                        onClick={receive}
                                        disabled={!warehouse}
                                    >
                                        {t('admin.confirmReceivedAndRestock')}
                                    </button>
                                </>
                            )}

                            {canRefund && (
                                <>
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
                                        <label className="form-label fs-13">{t('admin.referenceNumber')}</label>
                                        <input
                                            className="form-control"
                                            value={reference}
                                            onChange={(e) => setReference(e.target.value)}
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        className="btn btn-success"
                                        onClick={refund}
                                        disabled={!reference}
                                    >
                                        {t('admin.recordRefund')}
                                    </button>
                                </>
                            )}

                            {!needsShippingFeeConsent && !canApprove && !canReceive && !canRefund && (
                                <p className="text-muted mb-0">{t('admin.noActionAvailableAtThisStatus')}</p>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
