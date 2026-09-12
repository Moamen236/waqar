import { Head, Link } from '@inertiajs/react';
import Pagination from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { OrderSummary, PaginatedData } from '../../types';

// Ported from Admin Template/orders-list.html's table conventions.
export default function CheckingIndex({ orders }: { orders: PaginatedData<OrderSummary> }) {
    return (
        <AdminLayout title="Checking — Work Queue">
            <Head title="Checking" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">Orders Awaiting Review</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                        <th>Total</th>
                                        <th>Placed</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {orders.data.map((order) => (
                                        <tr key={order.id}>
                                            <td className="fw-medium">#{order.order_number}</td>
                                            <td>{order.customer?.name}</td>
                                            <td>
                                                <StatusBadge status={order.status} />
                                            </td>
                                            <td>{order.total}</td>
                                            <td>{new Date(order.created_at).toLocaleString()}</td>
                                            <td>
                                                <Link
                                                    href={route('admin.checking.show', order.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    Review
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {orders.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                Nothing awaiting review.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {orders.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={orders} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
