import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Assignee {
    id: number;
    name: string;
}

// The queue. One order goes through its own form (Delivery/Assign); a
// whole batch goes to one assignee from the bar above the table. What is
// already out has its own listing (Delivery/Orders).
export default function DeliveryIndex({
    ready,
    representatives,
    shippingCompanies,
}: {
    ready: PaginatedData<OrderSummary>;
    representatives: Assignee[];
    shippingCompanies: Assignee[];
}) {
    const { t, price, dateTime } = useTranslation();
    const [selected, setSelected] = useState<number[]>([]);
    // "representative:3" / "shipping_company:1" — one select over both
    // lists, because a batch goes to exactly one of them.
    const [choice, setChoice] = useState('');

    const allSelected = ready.data.length > 0 && selected.length === ready.data.length;

    function toggle(id: number) {
        setSelected((current) => (current.includes(id) ? current.filter((i) => i !== id) : [...current, id]));
    }

    async function assignSelected() {
        if (!choice || selected.length === 0) return;
        const [type, id] = choice.split(':');
        if (!(await confirmAction({ title: t('admin.assignSelectedQ', { count: selected.length }) }))) return;
        router.post(route('admin.delivery.assign.bulk'), {
            order_ids: selected,
            assignment_type: type,
            assignee_id: Number(id),
        });
    }

    return (
        <AdminLayout title={t('admin.deliveryAssignmentBoard')}>
            <Head title={t('admin.delivery')} />

            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <h4 className="card-title">{t('admin.readyToAssignConfirmed')}</h4>
                    <Link href={route('admin.delivery.orders')} className="btn btn-sm btn-outline-secondary">
                        {t('admin.navOutForDelivery')}
                    </Link>
                </div>

                {selected.length > 0 && (
                    <div className="card-body border-bottom d-flex flex-wrap align-items-end gap-2">
                        <div className="flex-grow-1" style={{ minWidth: 220 }}>
                            <label className="form-label">
                                {t('admin.assignSelected', { count: selected.length })}
                            </label>
                            <select
                                className="form-control"
                                value={choice}
                                onChange={(e) => setChoice(e.target.value)}
                            >
                                <option value="">{t('admin.select')}</option>
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
                            </select>
                        </div>
                        <button type="button" className="btn btn-primary" disabled={!choice} onClick={assignSelected}>
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
                                <th style={{ width: 40 }}>
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        aria-label={t('admin.selectAll')}
                                        checked={allSelected}
                                        disabled={ready.data.length === 0}
                                        onChange={() =>
                                            setSelected(allSelected ? [] : ready.data.map((order) => order.id))
                                        }
                                    />
                                </th>
                                <th>{t('admin.order')}</th>
                                <th>{t('admin.customer')}</th>
                                <th>{t('admin.governorate')}</th>
                                <th>{t('admin.city')}</th>
                                <th>{t('admin.district')}</th>
                                <th>{t('admin.area')}</th>
                                <th>{t('admin.total')}</th>
                                <th>{t('admin.createdAt')}</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {ready.data.map((order) => (
                                <tr key={order.id}>
                                    <td>
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            aria-label={`#${order.order_number}`}
                                            checked={selected.includes(order.id)}
                                            onChange={() => toggle(order.id)}
                                        />
                                    </td>
                                    <td>#{order.order_number}</td>
                                    <td>{order.customer?.name}</td>
                                    <td>{order.shipping_governorate?.name ?? '—'}</td>
                                    <td>{order.shipping_city?.name ?? '—'}</td>
                                    <td className="text-muted">{order.shipping_district?.name ?? '—'}</td>
                                    <td>{order.shipping_area?.name ?? '—'}</td>
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
                                        <Link
                                            href={route('admin.delivery.assign.form', order.id)}
                                            className="btn btn-soft-primary btn-sm"
                                        >
                                            {t('admin.assign')}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                            {ready.data.length === 0 && (
                                <EmptyRow colSpan={10} message={t('admin.nothingReadyToAssign')} />
                            )}
                        </tbody>
                    </table>
                </div>
                <PaginationFooter data={ready} />
            </div>
        </AdminLayout>
    );
}
