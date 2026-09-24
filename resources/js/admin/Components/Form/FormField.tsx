import { cloneElement, isValidElement, type ReactElement, type ReactNode } from 'react';
import { errorId, fieldId } from '../../lib/formErrors';
import FieldError from './FieldError';

/** The red asterisk on a field the server rejects the request without. */
export function RequiredMark() {
    return (
        <span className="text-danger ms-1" aria-hidden="true">
            *
        </span>
    );
}

type ControlProps = {
    id?: string;
    className?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

/**
 * Label + control + error, wired together.
 *
 * The control is passed as the single child and cloned with the id the
 * label points at, `aria-invalid`/`aria-describedby` tying it to its
 * message, and Bootstrap's `is-invalid` for the red border — so a page
 * writes its input exactly as before and gets all of that for free.
 *
 * A native element (input, select, textarea) takes those directly. A
 * component (react-select, a multi-select) gets the aria props and is
 * wrapped in `.field-invalid`, which admin.css uses to redden its border,
 * since its own markup can't take `is-invalid`.
 *
 * `name` is the server's error key, dotted for nested and repeated fields
 * (`name.en`, `items.0.quantity`).
 */
export default function FormField({
    name,
    label,
    error,
    required = false,
    hint,
    className = 'mb-3',
    children,
}: {
    name: string;
    label?: ReactNode;
    error?: string;
    required?: boolean;
    hint?: ReactNode;
    className?: string;
    children: ReactElement<ControlProps>;
}) {
    const existingId = isValidElement(children) ? children.props.id : undefined;
    const id = existingId ?? fieldId(name);
    const native = isValidElement(children) && typeof children.type === 'string';

    const control = cloneElement(children, {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': error ? errorId(id) : undefined,
        ...(native ? { className: `${children.props.className ?? ''}${error ? ' is-invalid' : ''}`.trim() } : {}),
    });

    return (
        <div className={className}>
            {label !== undefined && (
                <label className="form-label" htmlFor={id}>
                    {label}
                    {required && <RequiredMark />}
                </label>
            )}
            {native ? control : <div className={error ? 'field-invalid' : undefined}>{control}</div>}
            {hint && !error && <div className="form-text">{hint}</div>}
            <FieldError name={name} message={error} id={id} />
        </div>
    );
}
