import { Head, Link } from '@inertiajs/react';
import AdminLayout from '../../../Layouts/AdminLayout';

interface Company {
    id: number;
    name: string;
    delivery_fee: string;
    return_fee: string;
    open_statements_count: number;
}

export default function ReconciliationIndex({ shippingCompanies }: { shippingCompanies: Company[] }) {
    return (
        <AdminLayout title="Shipping Company Reconciliation">
            <Head title="Reconciliation" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">Shipping Companies</h4>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Company</th>
                                        <th>Delivery Fee</th>
                                        <th>Return Fee</th>
                                        <th>Open Statements</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {shippingCompanies.map((company) => (
                                        <tr key={company.id}>
                                            <td className="fw-medium">{company.name}</td>
                                            <td>{company.delivery_fee}</td>
                                            <td>{company.return_fee}</td>
                                            <td>
                                                {company.open_statements_count > 0 ? (
                                                    <span className="badge bg-warning-subtle text-warning px-2 py-1">
                                                        {company.open_statements_count} open
                                                    </span>
                                                ) : (
                                                    <span className="badge bg-success-subtle text-success px-2 py-1">
                                                        Settled
                                                    </span>
                                                )}
                                            </td>
                                            <td>
                                                <Link
                                                    href={route('admin.accounting.reconciliation.show', company.id)}
                                                    className="btn btn-soft-primary btn-sm"
                                                >
                                                    View Statements
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
