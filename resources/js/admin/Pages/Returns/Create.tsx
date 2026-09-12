import { Head, router } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';
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
    product_variant: { sku: string; product: { name: string } | null } | null;
}

interface OrderRecord {
    id: number;
    order_number: number;
    customer: { name: string };
    items: OrderItem[];
}

interface ReturnReason {
    id: number;
    name: string;
}

export default function ReturnsCreate({
    order,
    orderNumber,
    reasons,
}: {
    order: OrderRecord | null;
    orderNumber: string | null;
    reasons: ReturnReason[];
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(orderNumber ?? '');
    const [primaryReasonId, setPrimaryReasonId] = useState<number | ''>(reasons[0]?.id ?? '');
    const [notes, setNotes] = useState('');
    const [quantities, setQuantities] = useState<Record<number, number>>({});

    function lookupOrder(e: FormEvent) {
        e.preventDefault();
        router.get(route('admin.returns.create'), { order_number: search }, { preserveState: true });
    }

    function toggleItem(item: OrderItem, checked: boolean) {
        setQuantities((prev) => {
            const next = { ...prev };
            if (checked) {
                next[item.id] = item.quantity;
            } else {
                delete next[item.id];
            }
            return next;
        });
    }

    function submit() {
        if (!order || !primaryReasonId) return;
        const items = Object.entries(quantities).map(([orderItemId, quantity]) => ({
            order_item_id: Number(orderItemId),
            quantity,
        }));
        if (items.length === 0) return;

        router.post(route('admin.returns.store'), {
            order_id: order.id,
            primary_reason_id: primaryReasonId,
            customer_notes: notes || undefined,
            items,
        });
    }

    return (
        <AdminLayout title={t('admin.fileAReturn')}>
            <Head title={t('admin.fileAReturn')} />

            <div className="row">
                <div className="col-lg-5">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.findTheOrder')}</h4>
                        </div>
                        <div className="card-body">
                            <form onSubmit={lookupOrder} className="d-flex gap-2">
                                <input
                                    className="form-control"
                                    placeholder={t('admin.orderNumber')}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                                <button type="submit" className="btn btn-primary">
                                    {t('admin.lookUp')}
                                </button>
                            </form>
                            {orderNumber && !order && (
                                <div className="text-danger fs-13 mt-2">
                                    No Delivered order found with that number — a return can only be filed against a
                                    Delivered order.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {order && (
                <div className="row">
                    <div className="col-xl-12">
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">
                                    Order #{order.order_number} — {order.customer.name}
                                </h4>
                            </div>
                            <div className="card-body">
                                <div className="table-responsive mb-3">
                                    <table className="table align-middle mb-0 table-centered">
                                        <thead className="bg-light-subtle">
                                            <tr>
                                                <th />
                                                <th>{t('admin.product')}</th>
                                                <th>SKU</th>
                                                <th>{t('admin.orderedQty')}</th>
                                                <th>{t('admin.returnQty')}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {order.items.map((item) => (
                                                <tr key={item.id}>
                                                    <td>
                                                        <div className="form-check">
                                                            <input
                                                                type="checkbox"
                                                                className="form-check-input"
                                                                checked={item.id in quantities}
                                                                onChange={(e) => toggleItem(item, e.target.checked)}
                                                            />
                                                        </div>
                                                    </td>
                                                    <td>{item.product_name_snapshot}</td>
                                                    <td className="text-muted">{item.variant_sku_snapshot}</td>
                                                    <td>{item.quantity}</td>
                                                    <td>
                                                        <input
                                                            type="number"
                                                            className="form-control form-control-sm"
                                                            style={{ width: 80 }}
                                                            min={1}
                                                            max={item.quantity}
                                                            disabled={!(item.id in quantities)}
                                                            value={quantities[item.id] ?? ''}
                                                            onChange={(e) =>
                                                                setQuantities({
                                                                    ...quantities,
                                                                    [item.id]: Number(e.target.value),
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="row">
                                    <div className="col-lg-4">
                                        <div className="mb-3">
                                            <label className="form-label">{t('admin.reason')}</label>
                                            <select
                                                className="form-control"
                                                value={primaryReasonId}
                                                onChange={(e) => setPrimaryReasonId(Number(e.target.value))}
                                            >
                                                {reasons.map((reason) => (
                                                    <option key={reason.id} value={reason.id}>
                                                        {reason.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>
                                    <div className="col-lg-8">
                                        <div className="mb-3">
                                            <label className="form-label">
                                                {t('admin.notesWhatTheCustomerToldYou')}
                                            </label>
                                            <textarea
                                                className="form-control"
                                                rows={1}
                                                value={notes}
                                                onChange={(e) => setNotes(e.target.value)}
                                            />
                                        </div>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    className="btn btn-primary"
                                    onClick={submit}
                                    disabled={Object.keys(quantities).length === 0}
                                >
                                    {t('admin.fileReturn')}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
