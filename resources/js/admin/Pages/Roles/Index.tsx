import { Head, Link } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';

interface RoleRecord {
    id: number;
    name: string;
    permissions_count: number;
}

// Ported from Admin Template/role-list.html's table conventions.
export default function RolesIndex({ roles }: { roles: RoleRecord[] }) {
    const { t } = useTranslation();
    return (
        <AdminLayout title={t('admin.rolesPermissions')}>
            <Head title={t('admin.roles')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header">
                            <h4 className="card-title">{t('admin.roles')}</h4>
                            <p className="text-muted mb-0 fs-13">
                                Every screen or action is gated by a granular permission, never by role name directly —
                                this is where a Super Admin adjusts what each role can actually do.
                            </p>
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.role')}</th>
                                        <th>{t('admin.permissionsGranted')}</th>
                                        <th>{t('admin.action')}</th>
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
                                                        {t('admin.editPermissions')}
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
