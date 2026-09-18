import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { FormEventHandler } from 'react';
import AdminLayout from '../../Layouts/AdminLayout';
import { usePermissions } from '../../Hooks/usePermissions';
import { useTranslation } from '../../lib/useTranslation';

interface RoleRecord {
    id: number;
    name: string;
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
//
// Plain useState rather than react-hook-form: every permission name is
// itself `resource.action` (often with more than one dot —
// delivery.representatives.view), and RHF's `register("permissions." +
// permission)` treats each dot in the *path* as a nesting level. It was
// reading `defaultValues.permissions.delivery.representatives.view` — a
// four-level lookup — against a flat `permissions` object keyed by the
// literal dotted string, so the lookup never matched and no checkbox
// ever rendered pre-checked, however many permissions the role already
// held. A flat Set keyed by the permission string sidesteps that path
// parsing entirely.
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
    const { can } = usePermissions();
    const canSave = can('roles.update');
    const groups = useMemo(() => groupByDomain(allPermissions), [allPermissions]);

    const [selected, setSelected] = useState<Set<string>>(() => new Set(assigned));

    function toggle(permission: string) {
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(permission)) {
                next.delete(permission);
            } else {
                next.add(permission);
            }
            return next;
        });
    }

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();
        router.put(route('admin.roles.update', role.id), { permissions: Array.from(selected) });
    };

    return (
        <AdminLayout
            title={t('admin.permissionsFor', { role: t(`role.${role.name}`) })}
            breadcrumbs={[{ label: t('admin.rolesPermissions'), href: route('admin.roles.index') }]}
        >
            <Head title={t('admin.permissionsFor', { role: t(`role.${role.name}`) })} />
            <form onSubmit={onSubmit}>
                <div className="row">
                    {Object.entries(groups).map(([domain, permissions]) => (
                        <div className="col-lg-4" key={domain}>
                            <div className="card">
                                <div className="card-header">
                                    <h4 className="card-title">{t(`permissionGroup.${domain}`)}</h4>
                                </div>
                                <div className="card-body">
                                    {permissions.map((permission) => (
                                        <div className="form-check mb-2" key={permission}>
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                id={`perm-${permission}`}
                                                checked={selected.has(permission)}
                                                disabled={!canSave}
                                                onChange={() => toggle(permission)}
                                            />
                                            <label className="form-check-label" htmlFor={`perm-${permission}`}>
                                                {t(`permission.${permission}`)}
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                {canSave && (
                    <button type="submit" className="btn btn-primary">
                        {t('admin.savePermissions')}
                    </button>
                )}
            </form>
        </AdminLayout>
    );
}
