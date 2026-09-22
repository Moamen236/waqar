import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { usePermissions } from '../../Hooks/usePermissions';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface OutstandingOrder extends OrderSummary {
    payments: { id: number; amount: string; collected_amount: string | null }[];
}

interface Named {
    id: number;
    name: string;
}

export default function AccountingIndex({
    awaitingHandover,
    orders,
    outstanding,
    filters,
    representatives,
    shippingCompanies,
    treasuries,
}: {
    awaitingHandover: PaginatedData<OrderSummary>;
    orders: PaginatedData<OrderSummary>;
    outstanding: PaginatedData<OutstandingOrder>;
    filters: { representative_id: number | null; shipping_company_id: number | null };
    representatives: Named[];
    shippingCompanies: Named[];
    treasuries: Named[];
}) {
    const { t, price } = useTranslation();
    const { can } = usePermissions();
    const showInvoice = can('orders.view');
    const canConfirm = can('orders.confirm_delivery');

    const [selected, setSelected] = useState<number[]>([]);
    const [activeTab, setActiveTab] = useState<'handover' | 'delivery' | 'balance'>('handover');
    const [treasuryId, setTreasuryId] = useState<number>(treasuries[0]?.id ?? 0);
    const [collectedMethod, setCollectedMethod] = useState('cash');

    const allSelected = orders.data.length > 0 && selected.length === orders.data.length;
    const toggle = (id: number) =>
        setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

    // One select over two lists, same encoding the delivery board uses.
    const courierValue = filters.representative_id
        ? `representative:${filters.representative_id}`
        : filters.shipping_company_id
          ? `shipping_company:${filters.shipping_company_id}`
          : '';

    function selectCourier(value: string) {
        setSelected([]);
        const [type, id] = value.split(':');
        router.get(
            route('admin.accounting.index'),
            value === '' ? {} : type === 'representative' ? { representative_id: id } : { shipping_company_id: id },
            { preserveState: true, replace: true },
        );
    }

    // What should physically be in the courier's bag: net of the shipping
    // they keep on each order, mirroring Order::netOfShipping().
    const selectedNetTotal = orders.data
        .filter((order) => selected.includes(order.id))
        .reduce((sum, order) => sum + Math.max(0, Number(order.total) - Number(order.shipping_amount ?? 0)), 0);

    function handover(id: number) {
        router.post(route('admin.accounting.handover', id), {}, { preserveScroll: true });
    }

    async function settleSelected() {
        if (selected.length === 0 || !treasuryId) return;

        const confirmed = await confirmAction({
            title: t('admin.settleSelectedQ', { count: selected.length }),
            text: t('admin.settleSelectedHint', { amount: price(selectedNetTotal) }),
        });
        if (!confirmed) return;

        router.post(route('admin.accounting.settle.bulk'), {
            order_ids: selected,
            treasury_id: treasuryId,
            collected_method: collectedMethod,
        });
    }

    return (
        <AdminLayout title={t('admin.accountingDeliveryConfirmation')}>
            <Head title={t('admin.accounting')} />
            {/* One queue visible at a time — handover first, then
                settling what came back, then the leftover balances. */}
            <ul className="nav nav-pills mb-3">
                <li className="nav-item">
                    <button
                        type="button"
                        className={`nav-link ${activeTab === 'handover' ? 'active' : ''}`}
                        onClick={() => setActiveTab('handover')}
                    >
                        {t('admin.awaitingHandover')}
                        <span className="badge bg-light text-dark ms-2">{awaitingHandover.total}</span>
                    </button>
                </li>
                <li className="nav-item">
                    <button
                        type="button"
                        className={`nav-link ${activeTab === 'delivery' ? 'active' : ''}`}
                        onClick={() => setActiveTab('delivery')}
                    >
                        {t('admin.ordersAwaitingADeliveryResult')}
                        <span className="badge bg-light text-dark ms-2">{orders.total}</span>
                    </button>
                </li>
                <li className="nav-item">
                    <button
                        type="button"
                        className={`nav-link ${activeTab === 'balance' ? 'active' : ''}`}
                        onClick={() => setActiveTab('balance')}
                    >
                        {t('admin.awaitingBalance')}
                        <span className="badge bg-light text-dark ms-2">{outstanding.total}</span>
                    </button>
                </li>
            </ul>
            <div className="row">
                {activeTab === 'handover' && (
                    <div className="col-xl-12">
                        {/* Assigned, but still in the building. Signing these
                        out to the courier is a different job from settling
                        what comes back, so it gets its own queue. */}
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.awaitingHandover')}</h4>
                                <p className="text-muted fs-13 mb-0 mt-1">{t('admin.awaitingHandoverHint')}</p>
                            </div>
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            <th>{t('admin.order')}</th>
                                            <th>{t('admin.customer')}</th>
                                            <th>{t('admin.assignedTo')}</th>
                                            <th>{t('admin.total')}</th>
                                            <th>{t('admin.action')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {awaitingHandover.data.map((order) => (
                                            <tr key={order.id}>
                                                <td className="fw-medium">#{order.order_number}</td>
                                                <td>
                                                    <span className="d-block fw-medium">
                                                        {order.customer?.name ?? '—'}
                                                    </span>
                                                    <span className="text-muted fs-13" dir="ltr">
                                                        {order.customer?.phone ?? ''}
                                                    </span>
                                                </td>
                                                <td>
                                                    {order.delivery_representative?.name ??
                                                        order.shipping_company?.name ??
                                                        '—'}
                                                </td>
                                                <td>{order.total}</td>
                                                <td>
                                                    <div className="d-flex gap-1">
                                                        {canConfirm && (
                                                            <button
                                                                type="button"
                                                                className="btn btn-soft-primary btn-sm"
                                                                onClick={() => handover(order.id)}
                                                            >
                                                                {t('admin.confirmHandover')}
                                                            </button>
                                                        )}
                                                        <Link
                                                            href={route('admin.accounting.show', order.id)}
                                                            className="btn btn-soft-secondary btn-sm"
                                                        >
                                                            {t('admin.view')}
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {awaitingHandover.data.length === 0 && (
                                            <tr>
                                                <td colSpan={5} className="text-center text-muted py-4">
                                                    {t('admin.nothingAwaitingHandover')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationFooter data={awaitingHandover} />
                        </div>
                    </div>
                )}

                {activeTab === 'delivery' && (
                    <div className="col-xl-12">
                        <div className="card">
                            <div className="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                <h4 className="card-title flex-grow-1">{t('admin.ordersAwaitingADeliveryResult')}</h4>
                                {/* One courier turns up with a bag of cash for
                                everything they carried, so the queue narrows
                                to that person before it is settled. */}
                                <select
                                    className="form-select form-select-sm"
                                    style={{ maxWidth: 260 }}
                                    value={courierValue}
                                    onChange={(event) => selectCourier(event.target.value)}
                                >
                                    <option value="">{t('admin.allCouriers')}</option>
                                    {representatives.map((rep) => (
                                        <option key={`r${rep.id}`} value={`representative:${rep.id}`}>
                                            {rep.name}
                                        </option>
                                    ))}
                                    {shippingCompanies.map((company) => (
                                        <option key={`c${company.id}`} value={`shipping_company:${company.id}`}>
                                            {company.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {selected.length > 0 && (
                                <div className="bg-light-subtle border-top border-bottom p-3 d-flex flex-wrap align-items-end gap-2">
                                    <div className="flex-grow-1">
                                        <div className="fw-semibold">
                                            {t('admin.nOrdersSelected', { count: selected.length })}
                                        </div>
                                        {/* Net, not the sum of the totals: the
                                        courier keeps the shipping out of each
                                        one, so this is what should physically
                                        be in the bag. */}
                                        <div className="fs-13 text-muted">
                                            {t('admin.expectedFromCourier', { amount: price(selectedNetTotal) })}
                                        </div>
                                    </div>
                                    <select
                                        className="form-select form-select-sm"
                                        style={{ maxWidth: 180 }}
                                        value={treasuryId}
                                        onChange={(event) => setTreasuryId(Number(event.target.value))}
                                    >
                                        {treasuries.map((treasury) => (
                                            <option key={treasury.id} value={treasury.id}>
                                                {treasury.name}
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        className="form-select form-select-sm"
                                        style={{ maxWidth: 160 }}
                                        value={collectedMethod}
                                        onChange={(event) => setCollectedMethod(event.target.value)}
                                    >
                                        {['cash', 'bank_transfer', 'wallet', 'other'].map((method) => (
                                            <option key={method} value={method}>
                                                {t(`admin.method_${method}`)}
                                            </option>
                                        ))}
                                    </select>
                                    <button type="button" className="btn btn-sm btn-success" onClick={settleSelected}>
                                        {t('admin.settleSelected')}
                                    </button>
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-soft-secondary"
                                        onClick={() => setSelected([])}
                                    >
                                        {t('admin.clearSelection')}
                                    </button>
                                </div>
                            )}

                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            {canConfirm && (
                                                <th style={{ width: 40 }}>
                                                    <input
                                                        type="checkbox"
                                                        className="form-check-input"
                                                        checked={allSelected}
                                                        onChange={(event) =>
                                                            setSelected(
                                                                event.target.checked
                                                                    ? orders.data.map((order) => order.id)
                                                                    : [],
                                                            )
                                                        }
                                                    />
                                                </th>
                                            )}
                                            <th>{t('admin.order')}</th>
                                            <th>{t('admin.customer')}</th>
                                            <th>{t('admin.status')}</th>
                                            <th>{t('admin.assignedTo')}</th>
                                            <th>{t('admin.total')}</th>
                                            <th>{t('admin.action')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {orders.data.map((order) => (
                                            <tr key={order.id}>
                                                {canConfirm && (
                                                    <td>
                                                        <input
                                                            type="checkbox"
                                                            className="form-check-input"
                                                            checked={selected.includes(order.id)}
                                                            onChange={() => toggle(order.id)}
                                                        />
                                                    </td>
                                                )}
                                                <td className="fw-medium">#{order.order_number}</td>
                                                <td>
                                                    <span className="d-block fw-medium">
                                                        {order.customer?.name ?? '—'}
                                                    </span>
                                                    <span className="text-muted fs-13" dir="ltr">
                                                        {order.customer?.phone ?? ''}
                                                    </span>
                                                </td>
                                                <td>
                                                    <StatusBadge status={order.status} />
                                                </td>
                                                <td>
                                                    {order.delivery_representative?.name ??
                                                        order.shipping_company?.name ??
                                                        '—'}
                                                </td>
                                                <td>{order.total}</td>
                                                <td>
                                                    <div className="d-flex gap-1">
                                                        <Link
                                                            href={route('admin.accounting.show', order.id)}
                                                            className="btn btn-soft-primary btn-sm"
                                                        >
                                                            {t('admin.confirmResult')}
                                                        </Link>
                                                        {showInvoice && (
                                                            <Link
                                                                href={route('admin.orders.invoice', order.id)}
                                                                target="_blank"
                                                                className="btn btn-soft-secondary btn-sm"
                                                                title={t('admin.invoice')}
                                                                aria-label={t('admin.invoice')}
                                                            >
                                                                <i className="bx bx-printer" />
                                                            </Link>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {orders.data.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={canConfirm ? 7 : 6}
                                                    className="text-center text-muted py-4"
                                                >
                                                    {t('admin.nothingAwaitingADeliveryResult')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationFooter data={orders} />
                        </div>
                    </div>
                )}

                {activeTab === 'balance' && (
                    <div className="col-xl-12">
                        {/* Delivered, but the courier came back short. These
                        have left every other queue, so this is the only
                        place the open money is still visible. */}
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.awaitingBalance')}</h4>
                            </div>
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            <th>{t('admin.order')}</th>
                                            <th>{t('admin.customer')}</th>
                                            <th>{t('admin.status')}</th>
                                            <th>{t('admin.amountDue')}</th>
                                            <th>{t('admin.collectedSoFar')}</th>
                                            <th>{t('admin.stillOwed')}</th>
                                            <th>{t('admin.action')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {outstanding.data.map((order) => {
                                            const payment = order.payments[order.payments.length - 1];
                                            const due = Number(payment?.amount ?? 0);
                                            const collected = Number(payment?.collected_amount ?? 0);

                                            return (
                                                <tr key={order.id}>
                                                    <td className="fw-medium">#{order.order_number}</td>
                                                    <td>
                                                        <span className="d-block fw-medium">
                                                            {order.customer?.name ?? '—'}
                                                        </span>
                                                        <span className="text-muted fs-13" dir="ltr">
                                                            {order.customer?.phone ?? ''}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <StatusBadge status={order.status} />
                                                    </td>
                                                    <td>
                                                        <span dir="ltr" className="text-nowrap">
                                                            {price(due)}
                                                        </span>
                                                    </td>
                                                    <td className="text-muted">
                                                        <span dir="ltr" className="text-nowrap">
                                                            {price(collected)}
                                                        </span>
                                                    </td>
                                                    <td className="text-danger fw-medium">
                                                        <span dir="ltr" className="text-nowrap">
                                                            {price(Math.round((due - collected) * 100) / 100)}
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div className="d-flex gap-1">
                                                            <Link
                                                                href={route('admin.accounting.show', order.id)}
                                                                className="btn btn-soft-primary btn-sm"
                                                            >
                                                                {t('admin.recordCollection')}
                                                            </Link>
                                                            {showInvoice && (
                                                                <Link
                                                                    href={route('admin.orders.invoice', order.id)}
                                                                    target="_blank"
                                                                    className="btn btn-soft-secondary btn-sm"
                                                                    title={t('admin.invoice')}
                                                                    aria-label={t('admin.invoice')}
                                                                >
                                                                    <i className="bx bx-printer" />
                                                                </Link>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        {outstanding.data.length === 0 && (
                                            <tr>
                                                <td colSpan={7} className="text-center text-muted py-4">
                                                    {t('admin.nothingAwaitingBalance')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            <PaginationFooter data={outstanding} />
                        </div>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
