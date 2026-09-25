import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import CollectFromCourierModal from '../../Components/CollectFromCourierModal';
import DateRangeFilter from '../../Components/DateRangeFilter';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import { usePermissions } from '../../Hooks/usePermissions';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

/** Every row carries what the courier still owes on it — see Order::stillOwed(). */
interface CourierOrder extends OrderSummary {
    still_owed: number;
}

interface OutstandingOrder extends CourierOrder {
    payments: { id: number; amount: string; collected_amount: string | null }[];
}

interface Named {
    id: number;
    name: string;
}

interface CourierBalance {
    /** Same encoding as the courier select: `representative:ID` / `shipping_company:ID`. */
    key: string;
    name: string;
    orders: number;
    /** Goods still on the road, not yet paid for. */
    with_courier: number;
    /** Delivered, courier came back short. */
    delivered: number;
    owed: number;
}

type Tab = 'handover' | 'delivery' | 'balance';

// The courier select's encoding, for one order. Null for an order nobody
// is carrying, which can't be collected from anyone.
const courierOf = (order: OrderSummary) =>
    order.delivery_representative
        ? `representative:${order.delivery_representative.id}`
        : order.shipping_company
          ? `shipping_company:${order.shipping_company.id}`
          : null;

export default function AccountingIndex({
    awaitingHandover,
    orders,
    outstanding,
    courierBalances,
    filters,
    dates,
    representatives,
    shippingCompanies,
    treasuries,
}: {
    awaitingHandover: PaginatedData<CourierOrder>;
    orders: PaginatedData<CourierOrder>;
    outstanding: PaginatedData<OutstandingOrder>;
    courierBalances: CourierBalance[];
    filters: { representative_id: number | null; shipping_company_id: number | null };
    dates: { date_from: string | null; date_to: string | null };
    representatives: Named[];
    shippingCompanies: Named[];
    treasuries: Named[];
}) {
    const { t, price } = useTranslation();
    const { can } = usePermissions();
    const showInvoice = can('orders.print_invoice');
    // Every checkbox on these queues feeds a collect/settle action; confirming
    // a handover is its own grant.
    const canCollect = can('accounting.collect');
    const canHandover = can('accounting.confirm_handover');

    const [selected, setSelected] = useState<number[]>([]);
    const [activeTab, setActiveTab] = useState<Tab>('handover');
    const [treasuryId, setTreasuryId] = useState<number>(treasuries[0]?.id ?? 0);
    const [collectedMethod, setCollectedMethod] = useState('cash');
    const [collecting, setCollecting] = useState(false);

    const allSelected = orders.data.length > 0 && selected.length === orders.data.length;
    const toggle = (id: number) =>
        setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));

    // One selection, scoped to the tab it was made on — an order picked on
    // one queue must not ride along into another queue's action.
    function switchTab(tab: Tab) {
        setSelected([]);
        setActiveTab(tab);
    }

    const rows: CourierOrder[] =
        activeTab === 'handover' ? awaitingHandover.data : activeTab === 'delivery' ? orders.data : outstanding.data;
    const picked = rows.filter((order) => selected.includes(order.id));
    const pickedCourier = picked.length > 0 ? courierOf(picked[0]) : null;
    const pickedCourierName = picked[0]?.delivery_representative?.name ?? picked[0]?.shipping_company?.name ?? '';
    // Collecting one sum of cash only makes sense from one courier; the
    // server refuses anything else, this just says so before the click.
    const oneCourier = pickedCourier !== null && picked.every((order) => courierOf(order) === pickedCourier);
    // On the collect-only queues, once a courier is picked every other
    // courier's rows lock, so a mixed selection can't be built at all.
    const lockedOut = (order: CourierOrder) =>
        courierOf(order) === null || (pickedCourier !== null && courierOf(order) !== pickedCourier);

    // One select over two lists, same encoding the delivery board uses.
    const courierValue = filters.representative_id
        ? `representative:${filters.representative_id}`
        : filters.shipping_company_id
          ? `shipping_company:${filters.shipping_company_id}`
          : '';

    // Courier and date window travel together: changing one keeps the
    // other. An empty date_from is sent on purpose — it means "all dates";
    // leaving it out would snap the window back to today.
    function visit(next: { courier?: string; date_from?: string; date_to?: string }) {
        setSelected([]);
        const courier = next.courier ?? courierValue;
        const [type, id] = courier.split(':');
        const params: Record<string, string> = {
            date_from: next.date_from ?? dates.date_from ?? '',
            date_to: next.date_to ?? dates.date_to ?? '',
        };
        if (courier !== '') {
            params[type === 'representative' ? 'representative_id' : 'shipping_company_id'] = id;
        }
        router.get(route('admin.accounting.index'), params, { preserveState: true, replace: true });
    }

    const selectCourier = (value: string) => visit({ courier: value });

    // What should physically be in the courier's bag: net of the shipping
    // they keep on each order and of anything they prepaid at handover —
    // exactly Order::stillOwed().
    const selectedNetTotal = orders.data
        .filter((order) => selected.includes(order.id))
        .reduce((sum, order) => sum + Number(order.still_owed), 0);

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

    // The selection bar on the two collect-only queues. The delivery queue
    // keeps its own, which also offers the full-amount settle.
    const pickedOwed = Math.round(picked.reduce((sum, order) => sum + Number(order.still_owed), 0) * 100) / 100;
    const collectBar = canCollect && picked.length > 0 && (
        <div className="bg-light-subtle border-top border-bottom p-3 d-flex flex-wrap align-items-center gap-2">
            <div className="flex-grow-1">
                <div className="fw-semibold">
                    {t('admin.nOrdersSelected', { count: picked.length })} — {pickedCourierName}
                </div>
                <div className="fs-13 text-muted">{t('admin.expectedFromCourier', { amount: price(pickedOwed) })}</div>
            </div>
            <button
                type="button"
                className="btn btn-sm btn-success"
                disabled={!oneCourier}
                onClick={() => setCollecting(true)}
            >
                {t('admin.collectFromCourier')}
            </button>
            <button type="button" className="btn btn-sm btn-soft-secondary" onClick={() => setSelected([])}>
                {t('admin.clearSelection')}
            </button>
        </div>
    );

    return (
        <AdminLayout title={t('admin.accountingDeliveryConfirmation')}>
            <Head title={t('admin.accounting')} />
            {/* One queue visible at a time — handover first, then
                settling what came back, then the leftover balances. The
                courier filter sits above all three: settling is done one
                courier at a time whichever queue their orders are in. */}
            <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <ul className="nav nav-pills">
                    <li className="nav-item">
                        <button
                            type="button"
                            className={`nav-link ${activeTab === 'handover' ? 'active' : ''}`}
                            onClick={() => switchTab('handover')}
                        >
                            {t('admin.awaitingHandover')}
                            <span className="badge bg-light text-dark ms-2">{awaitingHandover.total}</span>
                        </button>
                    </li>
                    <li className="nav-item">
                        <button
                            type="button"
                            className={`nav-link ${activeTab === 'delivery' ? 'active' : ''}`}
                            onClick={() => switchTab('delivery')}
                        >
                            {t('admin.ordersAwaitingADeliveryResult')}
                            <span className="badge bg-light text-dark ms-2">{orders.total}</span>
                        </button>
                    </li>
                    <li className="nav-item">
                        <button
                            type="button"
                            className={`nav-link ${activeTab === 'balance' ? 'active' : ''}`}
                            onClick={() => switchTab('balance')}
                        >
                            {t('admin.awaitingBalance')}
                            <span className="badge bg-light text-dark ms-2">{outstanding.total}</span>
                        </button>
                    </li>
                </ul>
                <select
                    className="form-select form-select-sm"
                    style={{ maxWidth: 260 }}
                    value={courierValue}
                    onChange={(event) => selectCourier(event.target.value)}
                    aria-label={t('admin.allCouriers')}
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
            {/* Today by default; the per-courier totals on the balance tab
                ignore it, since what a courier owes is a running balance. */}
            <div className="mb-3">
                <DateRangeFilter from={dates.date_from} to={dates.date_to} onApply={(range) => visit(range)} />
            </div>
            <div className="row">
                {activeTab === 'handover' && (
                    <div className="col-xl-12">
                        {/* Assigned, but still in the building. Signing these
                        out to the courier is a different job from settling
                        what comes back, so it gets its own queue — but
                        handover is optional, so a courier who comes back
                        with cash for orders never signed out is settled
                        straight from here. */}
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.awaitingHandover')}</h4>
                                <p className="text-muted fs-13 mb-0 mt-1">{t('admin.awaitingHandoverHint')}</p>
                            </div>
                            {collectBar}
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            {canCollect && <th style={{ width: 40 }} />}
                                            <th>{t('admin.order')}</th>
                                            <th>{t('admin.customer')}</th>
                                            <th>{t('admin.assignedTo')}</th>
                                            <th>{t('admin.total')}</th>
                                            <th>{t('admin.owedByCourier')}</th>
                                            <th>{t('admin.action')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {awaitingHandover.data.map((order) => (
                                            <tr key={order.id}>
                                                {canCollect && (
                                                    <td>
                                                        <input
                                                            type="checkbox"
                                                            className="form-check-input"
                                                            checked={selected.includes(order.id)}
                                                            disabled={!selected.includes(order.id) && lockedOut(order)}
                                                            onChange={() => toggle(order.id)}
                                                            aria-label={`#${order.order_number}`}
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
                                                    {order.delivery_representative?.name ??
                                                        order.shipping_company?.name ??
                                                        '—'}
                                                </td>
                                                <td>{order.total}</td>
                                                <td>
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(order.still_owed)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <div className="d-flex gap-1">
                                                        {canHandover && (
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
                                                <td
                                                    colSpan={canCollect ? 7 : 6}
                                                    className="text-center text-muted py-4"
                                                >
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
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.ordersAwaitingADeliveryResult')}</h4>
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
                                    {/* When the bag is short: the full-amount
                                    settle above can't express that, so the
                                    same split the other queues use. One
                                    courier only, since it is one sum. */}
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-success"
                                        disabled={!oneCourier}
                                        title={oneCourier ? undefined : t('admin.selectSameCourier')}
                                        onClick={() => setCollecting(true)}
                                    >
                                        {t('admin.collectAnAmount')}
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
                                            {canCollect && (
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
                                                {canCollect && (
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
                                                    colSpan={canCollect ? 7 : 6}
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
                        {/* What each courier still owes, across all of their
                        short orders — every courier, whatever the filter, so
                        the whole picture is here. Picking a row filters the
                        list below to that courier. */}
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.balanceByCourier')}</h4>
                            </div>
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            <th>{t('admin.courier')}</th>
                                            <th>{t('admin.orders')}</th>
                                            <th>{t('admin.owedOnTheRoad')}</th>
                                            <th>{t('admin.owedDelivered')}</th>
                                            <th>{t('admin.stillOwed')}</th>
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {courierBalances.map((balance) => (
                                            <tr
                                                key={balance.key}
                                                className={balance.key === courierValue ? 'table-active' : ''}
                                            >
                                                <td className="fw-medium">{balance.name}</td>
                                                <td>{balance.orders}</td>
                                                <td>
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(balance.with_courier)}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(balance.delivered)}
                                                    </span>
                                                </td>
                                                <td className="text-danger fw-medium">
                                                    <span dir="ltr" className="text-nowrap">
                                                        {price(balance.owed)}
                                                    </span>
                                                </td>
                                                <td className="text-end">
                                                    <button
                                                        type="button"
                                                        className="btn btn-soft-primary btn-sm"
                                                        onClick={() =>
                                                            selectCourier(
                                                                balance.key === courierValue ? '' : balance.key,
                                                            )
                                                        }
                                                    >
                                                        {balance.key === courierValue
                                                            ? t('admin.showAllCouriers')
                                                            : t('admin.showOrders')}
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                        {courierBalances.length === 0 && (
                                            <tr>
                                                <td colSpan={6} className="text-center text-muted py-4">
                                                    {t('admin.nothingAwaitingBalance')}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Delivered, but the courier came back short. These
                        have left every other queue, so this is the only
                        place the open money is still visible. */}
                        <div className="card">
                            <div className="card-header">
                                <h4 className="card-title">{t('admin.awaitingBalance')}</h4>
                            </div>
                            {collectBar}
                            <div className="table-responsive">
                                <table className="table align-middle mb-0 table-hover table-centered">
                                    <thead className="bg-light-subtle">
                                        <tr>
                                            {canCollect && <th style={{ width: 40 }} />}
                                            <th>{t('admin.order')}</th>
                                            <th>{t('admin.customer')}</th>
                                            <th>{t('admin.assignedTo')}</th>
                                            <th>{t('admin.status')}</th>
                                            <th>{t('admin.amountDue')}</th>
                                            <th>{t('admin.collectedSoFar')}</th>
                                            <th>{t('admin.stillOwed')}</th>
                                            <th>{t('admin.action')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {outstanding.data.map((order) => {
                                            // Both net of the courier's shipping
                                            // fee, like `still_owed` — the gross
                                            // payment amount would overstate every
                                            // row by exactly the shipping.
                                            const payment = order.payments[order.payments.length - 1];
                                            const collected = Number(payment?.collected_amount ?? 0);
                                            const due = Math.round((collected + Number(order.still_owed)) * 100) / 100;

                                            return (
                                                <tr key={order.id}>
                                                    {canCollect && (
                                                        <td>
                                                            <input
                                                                type="checkbox"
                                                                className="form-check-input"
                                                                checked={selected.includes(order.id)}
                                                                disabled={
                                                                    !selected.includes(order.id) && lockedOut(order)
                                                                }
                                                                onChange={() => toggle(order.id)}
                                                                aria-label={`#${order.order_number}`}
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
                                                        {order.delivery_representative?.name ??
                                                            order.shipping_company?.name ??
                                                            '—'}
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
                                                            {price(order.still_owed)}
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
                                                <td
                                                    colSpan={canCollect ? 9 : 8}
                                                    className="text-center text-muted py-4"
                                                >
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

            <CollectFromCourierModal
                show={collecting && oneCourier}
                onHide={() => setCollecting(false)}
                onDone={() => {
                    setCollecting(false);
                    setSelected([]);
                }}
                courierName={pickedCourierName}
                orders={picked}
                treasuries={treasuries}
            />
        </AdminLayout>
    );
}
