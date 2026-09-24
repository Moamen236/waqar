import type { ReactNode } from 'react';

const ICONS = {
    danger: 'bx-error-circle',
    warning: 'bx-error',
    success: 'bx-check-circle',
    info: 'bx-info-circle',
} as const;

/**
 * An inline Bootstrap alert, for messages that belong on the page rather
 * than in a passing toast — a validation summary, a standing warning.
 * Transient news (saved, deleted) goes through lib/confirm's toasts.
 */
export default function AlertMessage({
    variant = 'danger',
    title,
    children,
    className = 'mb-3',
}: {
    variant?: keyof typeof ICONS;
    title?: ReactNode;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`alert alert-${variant} d-flex gap-2 ${className}`}
            role={variant === 'danger' ? 'alert' : 'status'}
        >
            <i className={`bx ${ICONS[variant]} fs-20 flex-shrink-0`} aria-hidden="true" />
            <div className="flex-grow-1">
                {title && <div className="fw-semibold">{title}</div>}
                {children}
            </div>
        </div>
    );
}
