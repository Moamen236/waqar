import { Link } from '@inertiajs/react';

/**
 * The "nothing here yet" panel. Larkon has no dedicated empty-state
 * component — its demo pages are always full of rows — so this is built
 * from the template's own primitives rather than invented from scratch:
 * the `avatar-lg bg-soft-* rounded` icon tile its dashboard cards use,
 * centred in a `card-body` with the `text-muted` copy scale the rest of
 * the admin already reads in.
 *
 * Before this, a listing with no rows rendered either a bare sentence
 * floating outside any card (Treasury) or a table header with nothing
 * under it at all (Reconciliation).
 */
export default function EmptyState({
    title,
    description,
    icon = 'bx-folder-open',
    actionHref,
    actionLabel,
}: {
    title: string;
    description?: string;
    /** Boxicons class without the `bx ` prefix. */
    icon?: string;
    actionHref?: string;
    actionLabel?: string;
}) {
    return (
        <div className="card-body text-center py-5">
            <div className="avatar-lg bg-soft-primary rounded mx-auto mb-3">
                <i className={`bx ${icon} avatar-title fs-32 text-primary`} />
            </div>
            <h5 className="mb-1">{title}</h5>
            {description && <p className="text-muted mb-0">{description}</p>}
            {actionHref && actionLabel && (
                <Link href={actionHref} className="btn btn-sm btn-primary mt-3">
                    <i className="bx bx-plus me-1 align-middle" />
                    {actionLabel}
                </Link>
            )}
        </div>
    );
}

/**
 * The same message as a table row, for listings that keep their column
 * header visible when empty. `colSpan` has to match the table's own
 * column count or the row breaks the grid.
 */
export function EmptyRow({
    colSpan,
    message,
    icon = 'bx-folder-open',
}: {
    colSpan: number;
    message: string;
    icon?: string;
}) {
    return (
        <tr>
            <td colSpan={colSpan} className="text-center text-muted py-4">
                <i className={`bx ${icon} fs-24 d-block mb-1 opacity-50`} />
                {message}
            </td>
        </tr>
    );
}
