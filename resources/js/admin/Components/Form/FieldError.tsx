import { errorId, fieldId } from '../../lib/formErrors';

/**
 * The message under a field. `name` is the server's error key; `data-error-for`
 * is how revealErrors() finds it to scroll to, and how it knows the error is
 * on screen rather than needing the fallback dialog.
 *
 * Pass `id` when the control's id isn't the default fieldId(name), so the
 * control's aria-describedby and this element's id still match.
 */
export default function FieldError({ name, message, id }: { name: string; message?: string; id?: string }) {
    if (!message) return null;

    return (
        <div id={errorId(id ?? fieldId(name))} className="invalid-feedback d-block" data-error-for={name}>
            {message}
        </div>
    );
}
