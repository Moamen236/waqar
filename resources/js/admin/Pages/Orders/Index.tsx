import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { confirmAction } from '../../lib/confirm';
import { EmptyRow } from '../../Components/EmptyState';
import ExportButton from '../../Components/ExportButton';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import StatCard from '../../Components/StatCard';
import StatusBadge from '../../Components/StatusBadge';
import RowActions from '../../Components/RowActions';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { GeoTree, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface OrderRow {
    id: number;
    order_number: number;
    status: string;
    payment_status: string;
    total: string;
    created_at: string;
    items_count: number;
    customer: { id: number; name: string; email: string; phone: string } | null;
    delivery_representative: { id: number; name: string } | null;
    shipping_company: { id: number; name: string } | null;
}

interface OrderFilters {
    status: string;
    q: string;
    customer: string;
    governorate_id: number | null;
    city_id: number | null;
    district_id: number | null;
    area_id: number | null;
    representative_id: number | null;
    shipping_company_id: number | null;
    date_from: string;
    date_to: string;
    qty_min: string;
    qty_max: string;
}

interface AssigneeOption {
    id: number;
    name: string;
}

const asId = (value: unknown): number | null =>
    value === '' || value === null || value === undefined ? null : Number(value);

const asText = (value: unknown): string => (value === null || value === undefined ? '' : String(value));

/**
 * Ported from Admin Template/orders-list.html: its row of summary tiles
 * above a single `table align-middle table-hover table-centered` card.
 *
 * The template's tiles are fixed demo counters (Payment Refund, Order
 * Cancel, …). These count the statuses this system actually has, and are
 * counted off the same `visibleTo()` scope as the rows below, so a
 * Customer Service Team Leader's totals can never disagree with the list
 * they are sitting on top of.
 *
 * The template's row actions are view/edit/delete. Only view survives
 * here: an order is not an editable record — it moves through Checking,
 * Delivery and Accounting, each of which owns its own transitions — and
 * there is no delete route for one at all.
 *
 * Beyond the header search/status pair, an "Advanced filters" panel adds
 * customer, shipping-destination cascade, assignee, placed-date range and
 * total-quantity range. Every filter rides the query string, so the Excel
 * export (which reads the same string through Order::filtered()) always
 * downloads exactly the rows on screen.
 */
export default function OrdersIndex({
    orders,
    filters,
    statuses,
    summary,
    geoTree,
    representatives,
    shippingCompanies,
}: {
    orders: PaginatedData<OrderRow>;
    filters: Partial<OrderFilters>;
    statuses: string[];
    summary: {
        awaiting_checking: number;
        in_delivery: number;
        delivered: number;
        cancelled_or_returned: number;
    };
    geoTree: GeoTree;
    representatives: AssigneeOption[];
    shippingCompanies: AssigneeOption[];
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();

    const f: OrderFilters = {
        status: asText(filters.status),
        q: asText(filters.q),
        customer: asText(filters.customer),
        governorate_id: asId(filters.governorate_id),
        city_id: asId(filters.city_id),
        district_id: asId(filters.district_id),
        area_id: asId(filters.area_id),
        representative_id: asId(filters.representative_id),
        shipping_company_id: asId(filters.shipping_company_id),
        date_from: asText(filters.date_from),
        date_to: asText(filters.date_to),
        qty_min: asText(filters.qty_min),
        qty_max: asText(filters.qty_max),
    };

    const advancedKeys: (keyof OrderFilters)[] = [
        'customer',
        'governorate_id',
        'city_id',
        'district_id',
        'area_id',
        'representative_id',
        'shipping_company_id',
        'date_from',
        'date_to',
        'qty_min',
        'qty_max',
    ];
    const advancedCount = advancedKeys.filter((key) => {
        const value = f[key];
        return value !== '' && value !== null;
    }).length;

    const [advancedOpen, setAdvancedOpen] = useState(advancedCount > 0);

    // Ticked rows, for an export or a batch print of just those orders.
    // Page-local on purpose: the checkboxes are on this page's rows, and
    // a selection that survived pagination would be invisible to whoever
    // made it. Same shape as the delivery board's batch assign.
    const [selected, setSelected] = useState<number[]>([]);
    const allSelected = orders.data.length > 0 && selected.length === orders.data.length;

    const toggle = (id: number) =>
        setSelected((current) => (current.includes(id) ? current.filter((i) => i !== id) : [...current, id]));

    // Text/date/number drafts apply on Enter (form submit), not per
    // keystroke — each apply is a server round-trip. Uncontrolled with a
    // key off the applied values: applying or clearing remounts the form
    // with fresh defaults, while typing never fights a re-render.
    const draftsKey = [f.customer, f.date_from, f.date_to, f.qty_min, f.qty_max].join('|');

    const governorate = geoTree.find((g) => g.id === f.governorate_id);
    const city = governorate?.cities.find((c) => c.id === f.city_id);

    // Soft delete, and only offered on an order that is already Cancelled:
    // cancelling is what releases the stock reservation, and deleting does
    // not — so the button is absent rather than disabled-and-explained on
    // an order that still holds stock. The server enforces the same rule.
    async function remove(id: number, orderNumber: number) {
        if (
            !(await confirmAction({
                title: t('admin.deleteOrderQ'),
                text: `#${orderNumber} — ${t('admin.deleteOrderHint')}`,
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.orders.destroy', id), { preserveScroll: true });
    }

    const queryParams = (base: OrderFilters): Record<string, string | number> => {
        const params: Record<string, string | number> = {};
        for (const [key, value] of Object.entries(base)) {
            if (value === '' || value === null || value === undefined) {
                // date_from is the exception: the server reads an absent
                // one as "hasn't chosen" and defaults it to today, so an
                // empty one has to survive — it is how "all dates" is
                // said. See App\Support\DateRangeFilter.
                if (key === 'date_from' && value === '') {
                    params[key] = '';
                }
                continue;
            }
            params[key] = value as string | number;
        }
        return params;
    };

    // The ticked ids on top of whatever the table is already filtered by,
    // so an export of a selection is still inside visibleTo() and the
    // current filters — the ids only narrow, they never widen.
    const selectionParams = (): Record<string, string | number | number[]> => ({
        ...queryParams(f),
        ids: selected,
    });

    const apply = (next: Partial<OrderFilters>) =>
        router.get(route('admin.orders.index'), queryParams({ ...f, ...next }), {
            preserveState: true,
            replace: true,
        });

    const applyDrafts = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        apply({
            customer: String(form.get('customer') ?? ''),
            date_from: String(form.get('date_from') ?? ''),
            date_to: String(form.get('date_to') ?? ''),
            qty_min: String(form.get('qty_min') ?? ''),
            qty_max: String(form.get('qty_max') ?? ''),
        });
    };

    const clearAll = () => router.get(route('admin.orders.index'), {}, { preserveState: true, replace: true });

    const selectValue = (value: number | null): string => (value === null ? '' : String(value));
    const selectId = (value: string): number | null => (value === '' ? null : Number(value));

    return (
        <AdminLayout title={t('admin.orderBook')}>
            <Head title={t('admin.orderBook')} />

            <div className="row mb-4">
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.awaitingChecking')}
                        value={summary.awaiting_checking}
                        icon="bx-check-square"
                        variant="warning"
                    />
                </div>
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.inDelivery')}
                        value={summary.in_delivery}
                        icon="bxs-truck"
                        variant="info"
                    />
                </div>
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.delivered')}
                        value={summary.delivered}
                        icon="bx-package"
                        variant="success"
                    />
                </div>
                <div className="col-md-6 col-xl-3">
                    <StatCard
                        label={t('admin.cancelledOrReturned')}
                        value={summary.cancelled_or_returned}
                        icon="bx-undo"
                        variant="danger"
                    />
                </div>
            </div>

            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h4 className="card-title flex-grow-1">{t('admin.allOrders')}</h4>

                            <SearchFilter
                                value={f.q ?? ''}
                                placeholder={t('admin.searchOrderOrPhone')}
                                onSubmit={(term) => apply({ q: term })}
                            >
                                <select
                                    className="form-select form-select-sm w-auto"
                                    aria-label={t('admin.allStatuses')}
                                    value={f.status ?? ''}
                                    onChange={(event) => apply({ status: event.target.value })}
                                >
                                    <option value="">{t('admin.allStatuses')}</option>
                                    {statuses.map((status) => (
                                        <option key={status} value={status}>
                                            {t(`status.${status}`)}
                                        </option>
                                    ))}
                                </select>
                            </SearchFilter>

                            <button
                                type="button"
                                className="btn btn-sm btn-soft-secondary d-flex align-items-center gap-1"
                                onClick={() => setAdvancedOpen((open) => !open)}
                            >
                                <i className="bx bx-filter-alt" />
                                {t('admin.advancedFilters')}
                                {advancedCount > 0 && <span className="badge bg-primary ms-1">{advancedCount}</span>}
                            </button>

                            {can('orders.export') && (
                                <ExportButton href={route('admin.orders.export', queryParams(f))} />
                            )}

                            {can('orders.create') && (
                                <Link
                                    href={route('admin.orders.create')}
                                    className="btn btn-sm btn-primary d-flex align-items-center"
                                >
                                    <i className="bx bx-plus me-1" />
                                    {t('admin.createOrder')}
                                </Link>
                            )}
                        </div>

                        {selected.length > 0 && (
                            <div className="card-body border-top d-flex flex-wrap align-items-center gap-2">
                                <span className="flex-grow-1 fw-medium">
                                    {t('admin.ordersSelected', { count: selected.length })}
                                </span>
                                {/* Plain anchors, not Inertia links: the export
                                    returns a binary download, and the print page
                                    is opened in its own tab so the selection on
                                    this one survives. */}
                                {can('orders.export') && (
                                    <a
                                        href={route('admin.orders.export', selectionParams())}
                                        className="btn btn-sm btn-soft-secondary d-flex align-items-center"
                                    >
                                        <i className="bx bx-download me-1" />
                                        {t('admin.exportSelected')}
                                    </a>
                                )}
                                <a
                                    href={route('admin.orders.invoices', selectionParams())}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-sm btn-soft-secondary d-flex align-items-center"
                                >
                                    <i className="bx bx-printer me-1" />
                                    {t('admin.printSelected')}
                                </a>
                                <a
                                    href={route('admin.orders.labels', selectionParams())}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="btn btn-sm btn-soft-secondary d-flex align-items-center"
                                >
                                    <i className="bx bx-package me-1" />
                                    {t('admin.printLabels')}
                                </a>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() => setSelected([])}
                                >
                                    {t('admin.clearSelection')}
                                </button>
                            </div>
                        )}

                        {advancedOpen && (
                            <div className="card-body border-top">
                                <form key={draftsKey} onSubmit={applyDrafts}>
                                    <div className="row g-3">
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.customer')}</label>
                                            <input
                                                name="customer"
                                                className="form-control form-control-sm"
                                                defaultValue={f.customer}
                                                placeholder={t('admin.searchOrderOrPhone')}
                                            />
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.governorate')}</label>
                                            <select
                                                className="form-control form-control-sm"
                                                value={selectValue(f.governorate_id)}
                                                onChange={(e) =>
                                                    apply({
                                                        governorate_id: selectId(e.target.value),
                                                        city_id: null,
                                                        district_id: null,
                                                        area_id: null,
                                                    })
                                                }
                                            >
                                                <option value="">{t('admin.select')}</option>
                                                {geoTree.map((g) => (
                                                    <option key={g.id} value={g.id}>
                                                        {g.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.city')}</label>
                                            <select
                                                className="form-control form-control-sm"
                                                value={selectValue(f.city_id)}
                                                onChange={(e) =>
                                                    apply({
                                                        city_id: selectId(e.target.value),
                                                        district_id: null,
                                                        area_id: null,
                                                    })
                                                }
                                            >
                                                <option value="">{t('admin.select')}</option>
                                                {governorate?.cities.map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        {c.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.districtOptional')}</label>
                                            <select
                                                className="form-control form-control-sm"
                                                value={selectValue(f.district_id)}
                                                onChange={(e) => apply({ district_id: selectId(e.target.value) })}
                                            >
                                                <option value="">{t('admin.none')}</option>
                                                {city?.districts.map((d) => (
                                                    <option key={d.id} value={d.id}>
                                                        {d.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.area')}</label>
                                            <select
                                                className="form-control form-control-sm"
                                                value={selectValue(f.area_id)}
                                                onChange={(e) => apply({ area_id: selectId(e.target.value) })}
                                            >
                                                <option value="">{t('admin.select')}</option>
                                                {city?.areas.map((a) => (
                                                    <option key={a.id} value={a.id}>
                                                        {a.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.representative')}</label>
                                            <select
                                                className="form-control form-control-sm"
                                                value={selectValue(f.representative_id)}
                                                onChange={(e) => apply({ representative_id: selectId(e.target.value) })}
                                            >
                                                <option value="">{t('admin.allRepresentatives')}</option>
                                                {representatives.map((r) => (
                                                    <option key={r.id} value={r.id}>
                                                        {r.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-md-4">
                                            <label className="form-label">{t('admin.shippingCompany')}</label>
                                            <select
                                                className="form-control form-control-sm"
                                                value={selectValue(f.shipping_company_id)}
                                                onChange={(e) =>
                                                    apply({ shipping_company_id: selectId(e.target.value) })
                                                }
                                            >
                                                <option value="">{t('admin.allShippingCompanies')}</option>
                                                {shippingCompanies.map((c) => (
                                                    <option key={c.id} value={c.id}>
                                                        {c.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-md-2">
                                            <label className="form-label">{t('admin.dateFrom')}</label>
                                            <input
                                                type="date"
                                                name="date_from"
                                                className="form-control form-control-sm"
                                                defaultValue={f.date_from}
                                            />
                                        </div>
                                        <div className="col-md-2">
                                            <label className="form-label">{t('admin.dateTo')}</label>
                                            <input
                                                type="date"
                                                name="date_to"
                                                className="form-control form-control-sm"
                                                defaultValue={f.date_to}
                                            />
                                        </div>
                                        <div className="col-md-2">
                                            <label className="form-label">{t('admin.minQuantity')}</label>
                                            <input
                                                type="number"
                                                min={0}
                                                name="qty_min"
                                                className="form-control form-control-sm"
                                                defaultValue={f.qty_min}
                                            />
                                        </div>
                                        <div className="col-md-2">
                                            <label className="form-label">{t('admin.maxQuantity')}</label>
                                            <input
                                                type="number"
                                                min={0}
                                                name="qty_max"
                                                className="form-control form-control-sm"
                                                defaultValue={f.qty_max}
                                            />
                                        </div>
                                        <div className="col-12 d-flex gap-2">
                                            <button type="submit" className="btn btn-sm btn-primary">
                                                {t('admin.applyFilters')}
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-soft-secondary"
                                                onClick={clearAll}
                                            >
                                                {t('admin.clearFilters')}
                                            </button>
                                            {/* Clearing the filters still leaves the order book on
                                                today — it is a history and that is its default.
                                                Opening the window back up is its own control. */}
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-soft-secondary"
                                                disabled={f.date_from === '' && f.date_to === ''}
                                                onClick={() => apply({ date_from: '', date_to: '' })}
                                            >
                                                {t('admin.allDates')}
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        )}

                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th className="ps-3" style={{ width: 40 }}>
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                aria-label={t('admin.selectAll')}
                                                checked={allSelected}
                                                disabled={orders.data.length === 0}
                                                onChange={() =>
                                                    setSelected(allSelected ? [] : orders.data.map((order) => order.id))
                                                }
                                            />
                                        </th>
                                        <th>{t('admin.orderNumber')}</th>
                                        <th>{t('admin.date')}</th>
                                        <th>{t('admin.customer')}</th>
                                        <th>{t('admin.items')}</th>
                                        <th>{t('admin.total')}</th>
                                        <th>{t('admin.paymentStatus')}</th>
                                        <th>{t('admin.assignedTo')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th className="pe-3">{t('admin.action')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.data.map((order) => (
                                        <tr key={order.id}>
                                            <td className="ps-3">
                                                <input
                                                    type="checkbox"
                                                    className="form-check-input"
                                                    aria-label={`#${order.order_number}`}
                                                    checked={selected.includes(order.id)}
                                                    onChange={() => toggle(order.id)}
                                                />
                                            </td>
                                            <td>
                                                <Link
                                                    href={route('admin.orders.show', order.id)}
                                                    className="fw-medium"
                                                    dir="ltr"
                                                >
                                                    #{order.order_number}
                                                </Link>
                                            </td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {dateTime(order.created_at)}
                                                </span>
                                            </td>
                                            <td>
                                                <span className="d-block fw-medium">{order.customer?.name ?? '—'}</span>
                                                <span className="text-muted fs-13" dir="ltr">
                                                    {order.customer?.phone ?? ''}
                                                </span>
                                            </td>
                                            <td dir="ltr">{order.items_count}</td>
                                            <td>
                                                <span dir="ltr" className="text-nowrap">
                                                    {price(Number(order.total))}
                                                </span>
                                            </td>
                                            <td>
                                                <StatusBadge status={order.payment_status} />
                                            </td>
                                            <td>
                                                {order.delivery_representative?.name ??
                                                    order.shipping_company?.name ?? (
                                                        <span className="text-muted">—</span>
                                                    )}
                                            </td>
                                            <td>
                                                <StatusBadge status={order.status} />
                                            </td>
                                            <td className="pe-3">
                                                <RowActions
                                                    viewHref={route('admin.orders.show', order.id)}
                                                    onDelete={
                                                        can('orders.delete') && order.status === 'Cancelled'
                                                            ? () => remove(order.id, order.order_number)
                                                            : undefined
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {orders.data.length === 0 && (
                                        <EmptyRow colSpan={10} message={t('admin.noOrdersMatch')} icon="bx-cart" />
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <PaginationFooter data={orders} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
