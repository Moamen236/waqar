import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import Modal from 'react-bootstrap/Modal';
import { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import SearchFilter from '../../Components/SearchFilter';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { confirmAction } from '../../lib/confirm';
import type { GeoTree, OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Assignee {
    id: number;
    name: string;
}

interface BoardFilters {
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

const BOARD_STATUSES = ['Confirmed', 'Assigned', 'Out for Delivery'] as const;

const ADVANCED_KEYS: (keyof BoardFilters)[] = [
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

const asId = (value: unknown): number | null =>
    value === '' || value === null || value === undefined ? null : Number(value);

const asText = (value: unknown): string => (value === null || value === undefined ? '' : String(value));

const selectValue = (value: number | null): string => (value === null ? '' : String(value));
const selectId = (value: string): number | null => (value === '' ? null : Number(value));

// One board for everything Delivery still has a hand in. Confirmed rows are
// waiting for a courier — assign one through its own form (Delivery/Assign),
// or tick several and hand the batch to one assignee from the bar. Assigned
// and Out for Delivery rows are already on the road; the delivery *outcome*
// is Accounting's, but who carries the parcel stays Delivery's, so those
// rows offer Move instead.
export default function DeliveryIndex({
    orders,
    filters,
    counts,
    geoTree,
    representatives,
    shippingCompanies,
}: {
    orders: PaginatedData<OrderSummary>;
    filters: Partial<BoardFilters>;
    counts: Record<string, number>;
    geoTree: GeoTree;
    representatives: Assignee[];
    shippingCompanies: Assignee[];
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();
    const canAssign = can('delivery.assign');
    const canMove = can('delivery.move');

    const f: BoardFilters = {
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

    const advancedCount = ADVANCED_KEYS.filter((key) => f[key] !== '' && f[key] !== null).length;
    const [advancedOpen, setAdvancedOpen] = useState(advancedCount > 0);

    const governorate = geoTree.find((g) => g.id === f.governorate_id);
    const city = governorate?.cities.find((c) => c.id === f.city_id);

    const apply = (next: Partial<BoardFilters>) => {
        const params: Record<string, string | number> = {};
        for (const [key, value] of Object.entries({ ...f, ...next })) {
            if (value !== '' && value !== null) params[key] = value;
        }
        router.get(route('admin.delivery.index'), params, { preserveState: true, replace: true });
    };

    // Text/date/number drafts apply on submit, not per keystroke — each
    // apply is a server round-trip. Keyed off the applied values so apply
    // or clear remounts the form with fresh defaults.
    const draftsKey = [f.customer, f.date_from, f.date_to, f.qty_min, f.qty_max].join('|');

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

    const clearAll = () => router.get(route('admin.delivery.index'), {}, { preserveState: true, replace: true });

    // Batch assign — only Confirmed rows are tickable; the rest already
    // have a courier.
    const assignable = orders.data.filter((order) => order.status === 'Confirmed');
    const [selected, setSelected] = useState<number[]>([]);
    const allSelected = assignable.length > 0 && selected.length === assignable.length;
    // "representative:3" / "shipping_company:1" — one select over both
    // lists, because an order goes to exactly one of them.
    const [batchChoice, setBatchChoice] = useState('');

    const toggle = (id: number) =>
        setSelected((current) => (current.includes(id) ? current.filter((i) => i !== id) : [...current, id]));

    async function assignSelected() {
        if (!batchChoice || selected.length === 0) return;
        const [type, id] = batchChoice.split(':');
        if (!(await confirmAction({ title: t('admin.assignSelectedQ', { count: selected.length }) }))) return;
        router.post(
            route('admin.delivery.assign.bulk'),
            { order_ids: selected, assignment_type: type, assignee_id: Number(id) },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    }

    // Move — hand an order already on the road to a different courier.
    const [moving, setMoving] = useState<OrderSummary | null>(null);
    const [moveChoice, setMoveChoice] = useState('');
    const [notes, setNotes] = useState('');
    const [processing, setProcessing] = useState(false);

    function openMove(order: OrderSummary) {
        setMoving(order);
        setMoveChoice('');
        setNotes('');
    }

    function closeMove() {
        setMoving(null);
        setMoveChoice('');
        setNotes('');
    }

    function move() {
        if (!moving || !moveChoice || processing) return;
        const [type, id] = moveChoice.split(':');
        setProcessing(true);
        router.post(
            route('admin.delivery.reassign', moving.id),
            { assignment_type: type, assignee_id: Number(id), notes: notes.trim() || undefined },
            { preserveScroll: true, onSuccess: closeMove, onFinish: () => setProcessing(false) },
        );
    }

    const currentCourier = moving
        ? (moving.delivery_representative?.name ?? moving.shipping_company?.name ?? null)
        : null;

    const courierOptions = (
        <>
            <optgroup label={t('admin.representatives')}>
                {representatives.map((rep) => (
                    <option key={`rep-${rep.id}`} value={`representative:${rep.id}`}>
                        {rep.name}
                    </option>
                ))}
            </optgroup>
            <optgroup label={t('admin.shippingCompanies')}>
                {shippingCompanies.map((company) => (
                    <option key={`co-${company.id}`} value={`shipping_company:${company.id}`}>
                        {company.name}
                    </option>
                ))}
            </optgroup>
        </>
    );

    const total = BOARD_STATUSES.reduce((sum, status) => sum + (counts[status] ?? 0), 0);
    const colSpan = canAssign ? 12 : 11;

    return (
        <AdminLayout title={t('admin.navDeliveryBoard')}>
            <Head title={t('admin.navDeliveryBoard')} />

            <div className="card">
                <div className="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <ul className="nav nav-pills flex-grow-1 gap-1">
                        {[{ value: '', label: t('admin.allStatuses'), count: total }]
                            .concat(
                                BOARD_STATUSES.map((status) => ({
                                    value: status,
                                    label: t(`status.${status}`),
                                    count: counts[status] ?? 0,
                                })),
                            )
                            .map((tab) => (
                                <li key={tab.value || 'all'} className="nav-item">
                                    <button
                                        type="button"
                                        className={`nav-link py-1 px-2 ${f.status === tab.value ? 'active' : ''}`}
                                        onClick={() => {
                                            setSelected([]);
                                            apply({ status: tab.value });
                                        }}
                                    >
                                        {tab.label}
                                        <span className="badge bg-light text-dark ms-1">{tab.count}</span>
                                    </button>
                                </li>
                            ))}
                    </ul>

                    <SearchFilter
                        value={f.q}
                        placeholder={t('admin.searchOrderOrPhone')}
                        onSubmit={(term) => apply({ q: term })}
                    />

                    <button
                        type="button"
                        className="btn btn-sm btn-soft-secondary d-flex align-items-center gap-1"
                        onClick={() => setAdvancedOpen((open) => !open)}
                    >
                        <i className="bx bx-filter-alt" />
                        {t('admin.advancedFilters')}
                        {advancedCount > 0 && <span className="badge bg-primary ms-1">{advancedCount}</span>}
                    </button>
                </div>

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
                                        onChange={(e) => apply({ shipping_company_id: selectId(e.target.value) })}
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
                                    <button type="button" className="btn btn-sm btn-soft-secondary" onClick={clearAll}>
                                        {t('admin.clearFilters')}
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                )}

                {canAssign && selected.length > 0 && (
                    <div className="card-body border-top d-flex flex-wrap align-items-end gap-2">
                        <div className="flex-grow-1" style={{ minWidth: 220 }}>
                            <label className="form-label">
                                {t('admin.assignSelected', { count: selected.length })}
                            </label>
                            <select
                                className="form-control"
                                value={batchChoice}
                                onChange={(e) => setBatchChoice(e.target.value)}
                            >
                                <option value="">{t('admin.select')}</option>
                                {courierOptions}
                            </select>
                        </div>
                        <button
                            type="button"
                            className="btn btn-primary"
                            disabled={!batchChoice}
                            onClick={assignSelected}
                        >
                            {t('admin.assign')}
                        </button>
                        <button type="button" className="btn btn-outline-secondary" onClick={() => setSelected([])}>
                            {t('admin.clearSelection')}
                        </button>
                    </div>
                )}

                <div className="table-responsive">
                    <table className="table align-middle mb-0 table-centered">
                        <thead className="bg-light-subtle">
                            <tr>
                                {canAssign && (
                                    <th style={{ width: 40 }}>
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            aria-label={t('admin.selectAll')}
                                            checked={allSelected}
                                            disabled={assignable.length === 0}
                                            onChange={() =>
                                                setSelected(allSelected ? [] : assignable.map((order) => order.id))
                                            }
                                        />
                                    </th>
                                )}
                                <th>{t('admin.order')}</th>
                                <th>{t('admin.customer')}</th>
                                <th>{t('admin.governorate')}</th>
                                <th>{t('admin.city')}</th>
                                <th>{t('admin.district')}</th>
                                <th>{t('admin.area')}</th>
                                <th>{t('admin.status')}</th>
                                <th>{t('admin.assignedTo')}</th>
                                <th>{t('admin.total')}</th>
                                <th>{t('admin.createdAt')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {orders.data.map((order) => {
                                const isConfirmed = order.status === 'Confirmed';
                                return (
                                    <tr key={order.id}>
                                        {canAssign && (
                                            <td>
                                                {isConfirmed && (
                                                    <input
                                                        type="checkbox"
                                                        className="form-check-input"
                                                        aria-label={`#${order.order_number}`}
                                                        checked={selected.includes(order.id)}
                                                        onChange={() => toggle(order.id)}
                                                    />
                                                )}
                                            </td>
                                        )}
                                        <td>#{order.order_number}</td>
                                        <td>{order.customer?.name}</td>
                                        <td>{order.shipping_governorate?.name ?? '—'}</td>
                                        <td>{order.shipping_city?.name ?? '—'}</td>
                                        <td className="text-muted">{order.shipping_district?.name ?? '—'}</td>
                                        <td>{order.shipping_area?.name ?? '—'}</td>
                                        <td>
                                            <StatusBadge status={order.status} />
                                        </td>
                                        <td>
                                            {order.delivery_representative?.name ?? order.shipping_company?.name ?? '—'}
                                        </td>
                                        <td>
                                            <span dir="ltr" className="text-nowrap">
                                                {price(Number(order.total))}
                                            </span>
                                        </td>
                                        <td>
                                            <span dir="ltr" className="text-nowrap">
                                                {dateTime(order.created_at)}
                                            </span>
                                        </td>
                                        <td className="text-end">
                                            {isConfirmed
                                                ? canAssign && (
                                                      <Link
                                                          href={route('admin.delivery.assign.form', order.id)}
                                                          className="btn btn-soft-primary btn-sm"
                                                      >
                                                          {t('admin.assign')}
                                                      </Link>
                                                  )
                                                : canMove && (
                                                      <button
                                                          type="button"
                                                          className="btn btn-sm btn-soft-primary d-inline-flex align-items-center gap-1"
                                                          onClick={() => openMove(order)}
                                                      >
                                                          <i className="bx bx-transfer" />
                                                          {t('admin.move')}
                                                      </button>
                                                  )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {orders.data.length === 0 && (
                                <EmptyRow colSpan={colSpan} message={t('admin.noOrdersMatch')} />
                            )}
                        </tbody>
                    </table>
                </div>
                <PaginationFooter data={orders} />
            </div>

            <Modal show={moving !== null} onHide={closeMove} centered>
                <Modal.Header closeButton>
                    <Modal.Title>
                        {t('admin.moveToAnotherCourier')}
                        {moving && <span dir="ltr"> #{moving.order_number}</span>}
                    </Modal.Title>
                </Modal.Header>
                <Modal.Body>
                    {currentCourier && (
                        <p className="text-muted">
                            {t('admin.assignedTo')}: <span className="text-dark">{currentCourier}</span>
                        </p>
                    )}
                    <div className="mb-3">
                        <label className="form-label">{t('admin.assignTo')}</label>
                        <select
                            className="form-control"
                            value={moveChoice}
                            onChange={(e) => setMoveChoice(e.target.value)}
                        >
                            <option value="">{t('admin.chooseCourier')}</option>
                            {courierOptions}
                        </select>
                    </div>
                    <div>
                        <label className="form-label">{t('admin.notes')}</label>
                        <textarea
                            className="form-control"
                            rows={2}
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                        />
                    </div>
                </Modal.Body>
                <Modal.Footer>
                    <button type="button" className="btn btn-soft-secondary" onClick={closeMove}>
                        {t('admin.cancel')}
                    </button>
                    <button
                        type="button"
                        className="btn btn-primary"
                        disabled={!moveChoice || processing}
                        onClick={move}
                    >
                        {t('admin.move')}
                    </button>
                </Modal.Footer>
            </Modal>
        </AdminLayout>
    );
}
