import { Head, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { useForm } from 'react-hook-form';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';

interface RoleRecord {
    id: number;
    name: string;
}

interface FormValues {
    permissions: Record<string, boolean>;
}

/**
 * Groups the flat permission list by its resource prefix (products.*,
 * orders.*, ...) purely for readability — the matrix itself is still one
 * role × its full permission set, submitted as one array.
 */
function groupByDomain(permissions: string[]): Record<string, string[]> {
    const groups: Record<string, string[]> = {};
    for (const permission of permissions) {
        const domain = permission.split('.')[0];
        groups[domain] ??= [];
        groups[domain].push(permission);
    }
    return groups;
}

// No Larkon page names a permission-matrix screen — role-add.html has no
// checkbox UI at all (Section 17's own audit) — so this reuses the same
// card grid convention every other module uses, per
// [[admin-ui-use-larkon-template]].
export default function RoleEdit({
    role,
    assigned,
    allPermissions,
}: {
    role: RoleRecord;
    assigned: string[];
    allPermissions: string[];
}) {
    const { t } = useTranslation();
    const groups = useMemo(() => groupByDomain(allPermissions), [allPermissions]);

    const { register, handleSubmit } = useForm<FormValues>({
        defaultValues: { permissions: Object.fromEntries(allPermissions.map((p) => [p, assigned.includes(p)])) },
    });

    function onSubmit(values: FormValues) {
        const permissions = Object.entries(values.permissions)
            .filter(([, checked]) => checked)
            .map(([name]) => name);

        router.put(route('admin.roles.update', role.id), { permissions });
    }

    return (
        <AdminLayout title={t('admin.permissionsFor', { role: role.name })}>
            <Head title={t('admin.permissionsFor', { role: role.name })} />
            <form onSubmit={handleSubmit(onSubmit)}>
                <div className="row">
                    {Object.entries(groups).map(([domain, permissions]) => (
                        <div className="col-lg-4" key={domain}>
                            <div className="card">
                                <div className="card-header">
                                    <h4 className="card-title text-capitalize">{domain}</h4>
                                </div>
                                <div className="card-body">
                                    {permissions.map((permission) => (
                                        <div className="form-check mb-2" key={permission}>
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                id={`perm-${permission}`}
                                                {...register(`permissions.${permission}`)}
                                            />
                                            <label className="form-check-label" htmlFor={`perm-${permission}`}>
                                                {permission}
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                <button type="submit" className="btn btn-primary">
                    {t('admin.savePermissions')}
                </button>
            </form>
        </AdminLayout>
    );
}
