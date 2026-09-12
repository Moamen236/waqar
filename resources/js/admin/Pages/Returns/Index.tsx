import { Head, Link, router } from '@inertiajs/react';
import Pagination from '../../Components/Pagination';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';

interface ReturnRecord {
    id: number;
    status: string;
    stage: string;
    created_at: string;
    order: { id: number; order_number: number; total: string };
    customer: { id: number; name: string; phone: string };
}

const STATUSES = ['requested', 'approved', 'received', 'inspected', 'refunded'];

export default function ReturnsIndex({
    returns,
    status,
}: {
    returns: PaginatedData<ReturnRecord>;
    status: string | null;
}) {
    return (
        <AdminLayout title="Returns & Refunds">
            <Head title="Returns" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Returns</h4>
                            <select
                                className="form-control form-control-sm"
                                style={{ width: 200 }}
                                value={status ?? ''}
                                onChange={(e) =>
                                    router.get(
                                        route('admin.returns.index'),
                                        { status: e.target.value || undefined },
                                        { preserveState: true },
                                    )
                                }
                            >
                                <option value="">All statuses</option>
                                {STATUSES.map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                            </select>
                            <Link href={route('admin.returns.create')} className="btn btn-sm btn-primary">
                                File a Return
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Stage</th>
                                        <th>Status</th>
                                        <th>Filed</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {returns.data.map((r) => (
                                        <tr key={r.id}>
                                            <td className="fw-medium">#{r.order.order_number}</td>
                                            <td>
                                                {r.customer.name}
                                                <div className="text-muted fs-13">{r.customer.phone}</div>
                                            </td>
                                            <td>{r.stage}</td>
                                            <td>
                                                <StatusBadge status={r.status} />
                                            </td>
                                            <td>{new Date(r.created_at).toLocaleDateString()}</td>
                                            <td>
                                                <Link
                                                    href={route('admin.returns.show', r.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                    {returns.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                No returns found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {returns.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={returns} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
