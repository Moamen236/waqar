import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { confirmAction } from '../../lib/confirm';
import type { OrderSummary, PaginatedData } from '../../types';

interface Assignee {
    id: number;
    name: string;
}

export default function DeliveryIndex({
    ready,
    active,
    representatives,
    shippingCompanies,
}: {
    ready: PaginatedData<OrderSummary>;
    active: PaginatedData<OrderSummary>;
    representatives: Assignee[];
    shippingCompanies: Assignee[];
}) {
    const [choice, setChoice] = useState<Record<number, string>>({});

    async function assign(orderId: number) {
        const value = choice[orderId];
        if (!value) return;
        const [type, id] = value.split(':');
        if (!(await confirmAction({ title: 'Assign this order?' }))) return;
        router.post(route('admin.delivery.assign', orderId), { assignment_type: type, assignee_id: Number(id) });
    }

    return (
        <AdminLayout title="Delivery Assignment Board">
            <Head title="Delivery" />

            <div className="row">
                <div className="col-xl-7">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">Ready to Assign (Confirmed)</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Assign To</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {ready.data.map((order) => (
                                        <tr key={order.id}>
                                            <td>#{order.order_number}</td>
                                            <td>{order.customer?.name}</td>
                                            <td>
                                                <select
                                                    className="form-control form-control-sm"
                                                    value={choice[order.id] ?? ''}
                                                    onChange={(e) =>
                                                        setChoice({ ...choice, [order.id]: e.target.value })
                                                    }
                                                >
                                                    <option value="">Select…</option>
                                                    <optgroup label="Representatives">
                                                        {representatives.map((rep) => (
                                                            <option
                                                                key={`rep-${rep.id}`}
                                                                value={`representative:${rep.id}`}
                                                            >
                                                                {rep.name}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                    <optgroup label="Shipping Companies">
                                                        {shippingCompanies.map((company) => (
                                                            <option
                                                                key={`co-${company.id}`}
                                                                value={`shipping_company:${company.id}`}
                                                            >
                                                                {company.name}
                                                            </option>
                                                        ))}
                                                    </optgroup>
                                                </select>
                                            </td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="btn btn-soft-primary btn-sm"
                                                    disabled={!choice[order.id]}
                                                    onClick={() => assign(order.id)}
                                                >
                                                    Assign
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                    {ready.data.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="text-center text-muted py-4">
                                                Nothing ready to assign.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {ready.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={ready} />
                            </div>
                        )}
                    </div>
                </div>

                <div className="col-xl-5">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">Out Today</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {active.data.map((order) => (
                                        <tr key={order.id}>
                                            <td>#{order.order_number}</td>
                                            <td>
                                                <StatusBadge status={order.status} />
                                            </td>
                                            <td>
                                                {order.delivery_representative?.name ??
                                                    order.shipping_company?.name ??
                                                    '—'}
                                            </td>
                                        </tr>
                                    ))}
                                    {active.data.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="text-center text-muted py-4">
                                                Nothing out for delivery.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {active.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={active} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
