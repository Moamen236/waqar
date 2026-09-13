import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Tab from 'react-bootstrap/Tab';
import Tabs from 'react-bootstrap/Tabs';
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

interface OrderDetail {
    id: number;
    order_number: number;
    status: string;
    total: string;
    customer: { name: string; email: string; phone: string };
    items: OrderItem[];
}

interface Treasury {
    id: number;
    name: string;
    type: string;
}

const COLLECTED_METHODS = ['cash', 'bank_transfer', 'wallet', 'other'];

// Ported from Admin Template/order-detail.html's Product table +
// Customer Details / Payment Information cards.
export default function AccountingShow({ order, treasuries }: { order: OrderDetail; treasuries: Treasury[] }) {
    const { t } = useTranslation();
    const [treasuryId, setTreasuryId] = useState<number | ''>(treasuries[0]?.id ?? '');
    const [collectedMethod, setCollectedMethod] = useState('cash');
    const [collectedAmount, setCollectedAmount] = useState(order.total);
    const [keptQuantities, setKeptQuantities] = useState<Record<number, number>>(
        Object.fromEntries(order.items.map((item) => [item.id, item.quantity])),
    );

    const canAct = ['Assigned', 'Out for Delivery'].includes(order.status);

    async function confirmDelivered() {
        if (!treasuryId) return;
        if (
            !(await confirmAction({
                title: t('admin.confirmDeliveredQ'),
                text: t('admin.confirmDeliveredHint'),
            }))
        )
            return;
        router.post(route('admin.accounting.delivered', order.id), {
            treasury_id: treasuryId,
            collected_method: collectedMethod,
            collected_amount: collectedAmount || undefined,
        });
    }

    async function confirmReturned() {
        if (
            !(await confirmAction({
                title: t('admin.confirmReturnedQ'),
                text: t('admin.confirmReturnedHint'),
            }))
        )
            return;
        router.post(route('admin.accounting.returned', order.id));
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
        router.post(route('admin.accounting.partially-returned', order.id), {
            treasury_id: treasuryId,
            collected_method: collectedMethod,
            collected_amount: collectedAmount,
            kept_quantities: keptQuantities,
        });
    }

    return (
        <AdminLayout
            title={t('admin.accountingForOrder', { number: order.order_number })}
            breadcrumbs={[{ label: t('admin.accountingDeliveryConfirmation'), href: route('admin.accounting.index') }]}
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
                                    </tr>
                                </thead>
                                <tbody>
                                    {order.items.map((item) => (
                                        <tr key={item.id}>
                                            <td>{item.product_name_snapshot}</td>
                                            <td className="text-muted">{item.variant_sku_snapshot}</td>
                                            <td>{item.quantity}</td>
                                            <td>{item.unit_price}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
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
                    {treasuries.map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.name} ({t.type})
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
                            {m}
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
