import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Modal from 'react-bootstrap/Modal';
import { EmptyRow } from '../../Components/EmptyState';
import { PaginationFooter } from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { OrderSummary, PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

interface Named {
    id: number;
    name: string;
}

// Orders already handed off — Assigned and Out for Delivery. The delivery
// *outcome* is Accounting's to record, but who is carrying the parcel
// stays Delivery's, so each row offers a Move action that opens a modal to
// hand the order to another courier.
export default function DeliveryOrders({
    orders,
    representatives,
    shippingCompanies,
}: {
    orders: PaginatedData<OrderSummary>;
    representatives: Named[];
    shippingCompanies: Named[];
}) {
    const { t, price, dateTime } = useTranslation();
    const { can } = usePermissions();
    const canAssign = can('orders.assign');
    const [moving, setMoving] = useState<OrderSummary | null>(null);
    // "representative:3" / "shipping_company:1" — one select over two
    // lists, because the order goes to exactly one of them.
    const [choice, setChoice] = useState('');
    const [notes, setNotes] = useState('');
    const [processing, setProcessing] = useState(false);

    function openMove(order: OrderSummary) {
        setMoving(order);
        setChoice('');
        setNotes('');
    }

    function closeMove() {
        setMoving(null);
        setChoice('');
        setNotes('');
    }

    function move() {
        if (!moving || !choice || processing) return;

        const [type, id] = choice.split(':');
        setProcessing(true);
        router.post(
            route('admin.delivery.reassign', moving.id),
            { assignment_type: type, assignee_id: Number(id), notes: notes.trim() || undefined },
            {
                preserveScroll: true,
                onSuccess: closeMove,
                onFinish: () => setProcessing(false),
            },
        );
    }

    const currentCourier = moving
        ? (moving.delivery_representative?.name ?? moving.shipping_company?.name ?? null)
        : null;

    return (
        <AdminLayout
            title={t('admin.navOutForDelivery')}
            breadcrumbs={[{ label: t('admin.deliveryAssignmentBoard'), href: route('admin.delivery.index') }]}
        >
            <Head title={t('admin.navOutForDelivery')} />

            <div className="card">
                <div className="card-header d-flex justify-content-between align-items-center">
                    <h4 className="card-title">{t('admin.navOutForDelivery')}</h4>
                    <Link href={route('admin.delivery.index')} className="btn btn-sm btn-outline-secondary">
                        {t('admin.readyToAssignConfirmed')}
                    </Link>
                </div>
                <div className="table-responsive">
                    <table className="table align-middle mb-0 table-centered">
                        <thead className="bg-light-subtle">
                            <tr>
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
                                {canAssign && <th>{t('admin.action')}</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {orders.data.map((order) => (
                                <tr key={order.id}>
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
                                    {canAssign && (
                                        <td>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-soft-primary d-flex align-items-center gap-1"
                                                onClick={() => openMove(order)}
                                            >
                                                <i className="bx bx-transfer" />
                                                {t('admin.move')}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                            {orders.data.length === 0 && (
                                <EmptyRow colSpan={canAssign ? 11 : 10} message={t('admin.nothingOutForDelivery')} />
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
                        <select className="form-control" value={choice} onChange={(e) => setChoice(e.target.value)}>
                            <option value="">{t('admin.chooseCourier')}</option>
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
                    <button type="button" className="btn btn-primary" disabled={!choice || processing} onClick={move}>
                        {t('admin.move')}
                    </button>
                </Modal.Footer>
            </Modal>
        </AdminLayout>
    );
}
