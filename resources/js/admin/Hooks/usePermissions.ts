import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../types';

/**
 * Mirrors the server's authorization boundary for UI purposes only (show/
 * hide nav links and action buttons) — the real enforcement is the
 * `permission:` route middleware (Section 15: "gated by a granular
 * permission"). Super Admin bypasses every check here the same way it
 * does server-side (AppServiceProvider's Gate::before), rather than
 * needing every permission explicitly listed.
 */
export function usePermissions() {
    const { auth } = usePage<SharedProps>().props;
    const employee = auth.employee;

    function can(permission: string): boolean {
        if (!employee) return false;
        if (employee.is_super_admin) return true;

        return employee.permissions.includes(permission);
    }

    function canAny(permissions: string[]): boolean {
        return permissions.some(can);
    }

    return { employee, can, canAny };
}
