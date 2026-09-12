import { Head, Link } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';

interface RoleRecord {
    id: number;
    name: string;
    permissions_count: number;
}

// Ported from Admin Template/role-list.html's table conventions.
export default function RolesIndex({ roles }: { roles: RoleRecord[] }) {
    return (
        <AdminLayout title="Roles & Permissions">
            <Head title="Roles" />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">Roles</h4>
                            <p className="text-muted mb-0 fs-13">
                                Every screen or action is gated by a granular permission, never by role name directly —
                                this is where a Super Admin adjusts what each role can actually do.
                            </p>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>Role</th>
                                        <th>Permissions Granted</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {roles.map((role) => (
                                        <tr key={role.id}>
                                            <td className="fw-medium">{role.name}</td>
                                            <td>
                                                {role.name === 'Super Admin'
                                                    ? 'All (bypasses checks)'
                                                    : role.permissions_count}
                                            </td>
                                            <td>
                                                {role.name !== 'Super Admin' && (
                                                    <Link
                                                        href={route('admin.roles.edit', role.id)}
                                                        className="btn btn-soft-primary btn-sm"
                                                    >
                                                        Edit Permissions
                                                    </Link>
                                                )}
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
