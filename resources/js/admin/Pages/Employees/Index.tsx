import { Head, Link, router } from '@inertiajs/react';
import { confirmAction } from '../../lib/confirm';
import { PaginationFooter } from '../../Components/Pagination';
import RowActions from '../../Components/RowActions';
import StatusBadge from '../../Components/StatusBadge';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import type { PaginatedData } from '../../types';
import { useTranslation } from '../../lib/useTranslation';

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
    const { t } = useTranslation();
    const { can, employee: currentEmployee } = usePermissions();

    async function remove(id: number, name: string) {
        if (
            !(await confirmAction({
                title: t('admin.deleteConfirmQ', { name }),
                confirmText: t('admin.delete'),
                danger: true,
            }))
        ) {
            return;
        }

        router.delete(route('admin.employees.destroy', id), { preserveScroll: true });
    }

    return (
        <AdminLayout title={t('admin.employees')}>
            <Head title={t('admin.employees')} />
            <div className="row">
                <div className="col-xl-12">
                    <div className="card">
                        <div className="card-header d-flex justify-content-between align-items-center gap-1">
                            <h4 className="card-title flex-grow-1">{t('admin.allEmployees')}</h4>
                            {can('employees.create') && (
                                <Link href={route('admin.employees.create')} className="btn btn-sm btn-primary">
                                    {t('admin.addEmployee')}
                                </Link>
                            )}
                        </div>
                        <div className="table-responsive">
                            <table className="table align-middle mb-0 table-hover table-centered">
                                <thead className="bg-light-subtle">
                                    <tr>
                                        <th>{t('admin.name')}</th>
                                        <th>{t('admin.email')}</th>
                                        <th>{t('admin.phone')}</th>
                                        <th>{t('admin.role')}</th>
                                        <th>{t('admin.status')}</th>
                                        <th>{t('admin.action')}</th>
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
                                                        {t(`role.${r.name}`)}
                                                    </span>
                                                ))}
                                            </td>
                                            <td>
                                                <StatusBadge status={employee.is_active ? 'active' : 'inactive'} />
                                            </td>
                                            <td>
                                                <RowActions
                                                    editHref={
                                                        can('employees.update')
                                                            ? route('admin.employees.edit', employee.id)
                                                            : undefined
                                                    }
                                                    onDelete={
                                                        can('employees.delete') && employee.id !== currentEmployee?.id
                                                            ? () => remove(employee.id, employee.full_name)
                                                            : undefined
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {employees.data.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-center text-muted py-4">
                                                {t('admin.noEmployeesFound')}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <PaginationFooter data={employees} />
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
