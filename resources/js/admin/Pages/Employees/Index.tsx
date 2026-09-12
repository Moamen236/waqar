import { Head, Link } from '@inertiajs/react';
import Pagination from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import type { PaginatedData } from '../../types';

interface EmployeeRecord {
    id: number;
    full_name: string;
    email: string;
    phone: string;
    is_active: boolean;
    roles: { id: number; name: string }[];
}

// Ported from Admin Template/role-list.html's table conventions.
export default function EmployeesIndex({ employees }: { employees: PaginatedData<EmployeeRecord> }) {
    return (
        <AdminLayout title="Employees">
            <Head title="Employees" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">All Employees</h4>
                            <Link href={route('admin.employees.create')} className="btn btn-sm btn-primary">
                                Add Employee
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {employees.data.map((employee) => (
                                        <tr key={employee.id}>
                                            <td className="fw-medium">{employee.full_name}</td>
                                            <td>{employee.email}</td>
                                            <td>{employee.phone}</td>
                                            <td>
                                                {employee.roles.map((r) => (
                                                    <span
                                                        key={r.id}
                                                        className="badge bg-primary-subtle text-primary px-2 py-1 me-1"
                                                    >
                                                        {r.name}
                                                    </span>
                                                ))}
                                            </td>
                                            <td>
                                                <StatusBadge status={employee.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions editHref={route('admin.employees.edit', employee.id)} />
                                            </td>
                                        </tr>
                                    ))}
                                    {employees.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                No employees found.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {employees.data.length > 0 && (
                            <div className="card-footer border-top">
                                <Pagination data={employees} />
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
