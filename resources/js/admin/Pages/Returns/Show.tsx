import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { useTranslation } from '../../lib/useTranslation';

interface ReturnItem {
    id: number;
    quantity: number;
    order_item: {
        unit_price: string;
        // The name and SKU as sold. order_items snapshots both at
        // checkout, so they stay correct — and stay *renderable* —
        // after a product is soft-deleted, which nulls the relation.
        product_name_snapshot: string;
        variant_sku_snapshot: string;
        product_variant: { sku: string; product: { name: string } | null } | null;
    };
}

interface ReturnDetail {
    id: number;
    status: string;
    stage: string;
    return_shipping_fee: string | null;
    customer_accepted_return_shipping_fee_at: string | null;
    customer_notes: string | null;
    order: { id: number; order_number: number; customer: { name: string; phone: string } };
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

const REFUND_METHODS = ['bank_transfer', 'wallet'];

// Ported from Admin Template/order-detail.html's Product table + summary
// card conventions.
export default function ReturnsShow({
    return: ret,
    warehouses,
    treasuries,
}: {
    return: ReturnDetail;
    warehouses: Option[];
    treasuries: Treasury[];
}) {
    const { t } = useTranslation();
    const [shippingFee, setShippingFee] = useState('0');
    const [warehouseId, setWarehouseId] = useState<number | ''>(warehouses[0]?.id ?? '');
    const [treasuryId, setTreasuryId] = useState<number | ''>(treasuries[0]?.id ?? '');
    const [method, setMethod] = useState('bank_transfer');
    const [reference, setReference] = useState('');

    const needsShippingFeeConsent =
        ret.stage === 'post_delivery' && ret.status === 'requested' && !ret.customer_accepted_return_shipping_fee_at;
    const canApprove =
        ret.status === 'requested' && (ret.stage !== 'post_delivery' || ret.customer_accepted_return_shipping_fee_at);
    const canReceive = ret.status === 'approved';
    const canRefund = ret.status === 'inspected';

    async function acceptFee() {
        if (!(await confirmAction({ title: 'Record the accepted return shipping fee?' }))) return;
        router.post(route('admin.returns.accept-shipping-fee', ret.id), { return_shipping_fee: shippingFee });
    }

    async function approve() {
        if (!(await confirmAction({ title: 'Approve this return?' }))) return;
        router.post(route('admin.returns.approve', ret.id));
    }

    async function receive() {
        if (!warehouseId) return;
        if (
            !(await confirmAction({
                title: 'Confirm items received?',
                text: 'Sellable items are restocked at this warehouse.',
            }))
        )
            return;
        router.post(route('admin.returns.receive', ret.id), { warehouse_id: warehouseId });
    }

    async function refund() {
        if (!treasuryId || !reference) return;
        if (!(await confirmAction({ title: 'Record this refund?' }))) return;
        router.post(route('admin.returns.refund', ret.id), {
            treasury_id: treasuryId,
            method,
            reference_number: reference,
        });
    }

    return (
        <AdminLayout title={`Return for Order #${ret.order.order_number}`}>
            <Head title={t('admin.returnForOrder', { number: ret.order.order_number })} />

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
                                    </tr>
                                </thead>
                                <tbody>
                                    {ret.items.map((item) => (
                                        <tr key={item.id}>
                                            <td>{item.order_item.product_name_snapshot}</td>
                                            <td className="text-muted">{item.order_item.variant_sku_snapshot}</td>
                                            <td>{item.quantity}</td>
                                            <td>{item.order_item.unit_price}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {ret.refund && (
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.refund')}</h4>
                            </div>
                            <div className="card-body">
                                <p className="mb-1">
                                    Net amount: <strong>{ret.refund.net_amount}</strong>
                                </p>
                                <p className="mb-0 text-muted">
                                    {ret.refund.method} — ref. {ret.refund.reference_number}
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
                                Order #{ret.order.order_number} — {ret.order.customer.name}
                            </p>
                            <p className="mb-1 text-muted">{ret.order.customer.phone}</p>
                            <p className="mb-1">
                                Stage: <span className="badge bg-light text-dark border px-2 py-1">{ret.stage}</span>
                            </p>
                            <p className="mb-1">Reason: {ret.reason?.name}</p>
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
                                    <div className="mb-2">
                                        <label className="form-label fs-13">{t('admin.receivingWarehouse')}</label>
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
                                    <button type="button" className="btn btn-primary" onClick={receive}>
                                        Confirm Received &amp; Restock
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
                                            {treasuries.map((t) => (
                                                <option key={t.id} value={t.id}>
                                                    {t.name} ({t.type})
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="mb-2">
                                        <label className="form-label fs-13">{t('admin.method')}</label>
                                        <select
                                            className="form-control"
                                            value={method}
                                            onChange={(e) => setMethod(e.target.value)}
                                        >
                                            {REFUND_METHODS.map((m) => (
                                                <option key={m} value={m}>
                                                    {m}
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
