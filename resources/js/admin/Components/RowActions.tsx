import { Link } from '@inertiajs/react';

/**
 * The view/edit/delete button trio Larkon's own list pages use
 * consistently (product-list.html, customer-list.html, role-list.html,
 * ...) — `btn-light` for view, `btn-soft-primary` for edit,
 * `btn-soft-danger` for delete, Boxicons rather than the template's raw
 * iconify-icon markup (see AdminLayout.tsx's note on why).
 */
export default function RowActions({
    viewHref,
    editHref,
    onDelete,
}: {
    viewHref?: string;
    editHref?: string;
    onDelete?: () => void;
}) {
    return (
        <div className="d-flex gap-2">
            {viewHref && (
                <Link href={viewHref} className="btn btn-light btn-sm">
                    <i className="bx bx-show align-middle fs-18" />
                </Link>
            )}
            {editHref && (
                <Link href={editHref} className="btn btn-soft-primary btn-sm">
                    <i className="bx bx-edit-alt align-middle fs-18" />
                </Link>
            )}
            {onDelete && (
                <button type="button" className="btn btn-soft-danger btn-sm" onClick={onDelete}>
                    <i className="bx bx-trash align-middle fs-18" />
                </button>
            )}
        </div>
    );
}
