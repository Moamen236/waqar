import { Head, Link } from '@inertiajs/react';
import Pagination from '../../../Components/Pagination';
import RowActions from '../../../Components/RowActions';
import StatusBadge from '../../../Components/StatusBadge';
import AdminLayout from '../../../Layouts/AdminLayout';
import type { PaginatedData } from '../../../types';

interface Representative {
    id: number;
    name: string;
    phone: string;
    status: string;
    areas_count: number;
}

export default function RepresentativesIndex({ representatives }: { representatives: PaginatedData<Representative> }) {
    return (
        <AdminLayout title="Delivery Representatives">
            <Head title="Representatives" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Representatives</h4>
                            <Link
                                href={route('admin.delivery.representatives.create')}
                                className="btn btn-sm btn-primary"
                            >
                                Add Representative
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Coverage Areas</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {representatives.data.map((rep) => (
                                        <tr key={rep.id}>
                                            <td className="fw-medium">{rep.name}</td>
                                            <td>{rep.phone}</td>
                                            <td>
                                                <StatusBadge status={rep.status} />
                                            </td>
                                            <td>{rep.areas_count}</td>
                                            <td>
                                                <div className="d-flex gap-2">
                                                    <Link
                                                        href={route('admin.delivery.representatives.areas', rep.id)}
                                                        className="btn btn-light btn-sm"
                                                    >
                                                        <i className="bx bx-map align-middle fs-18" />
                                                    </Link>
                                                    <RowActions
                                                        editHref={route('admin.delivery.representatives.edit', rep.id)}
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {representatives.data.length === 0 && (
                                        <tr>
                                            <td colSpan={5} className="text-center text-muted py-4">
                                                No representatives yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {representatives.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={representatives} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
