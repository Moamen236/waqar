import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { notifyError, notifyFieldErrors, notifyValidation } from './confirm';

/**
 * Validation plumbing shared by every admin form.
 *
 * Laravel answers a failed validation with `{ "field.path": "message" }`,
 * keyed exactly as the request was shaped — `name.en`, `items.0.quantity`,
 * `addresses.2.city_id`. Every piece here works off those keys, so a form
 * never translates between its own field names and the server's.
 */

/** A DOM-safe id for a server error key (`items.0.quantity` → `field-items-0-quantity`). */
export function fieldId(name: string): string {
    return `field-${name.replace(/[^A-Za-z0-9_-]/g, '-')}`;
}

export function errorId(controlId: string): string {
    return `${controlId}-error`;
}

/**
 * The attributes that tie a control to its error message for assistive
 * tech, plus the Bootstrap class that draws the red border. For controls a
 * page renders itself (checkboxes, repeater cells); FormField applies the
 * same thing automatically.
 */
export function invalidProps(name: string, error?: string, id: string = fieldId(name)) {
    return {
        id,
        'aria-invalid': error ? (true as const) : undefined,
        'aria-describedby': error ? errorId(id) : undefined,
    };
}

export const invalidClass = (error?: string) => (error ? ' is-invalid' : '');

const FOCUSABLE = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * After a failed submit: bring the first error into view and put the caret
 * in its field, and make sure no message is lost.
 *
 * "Lost" was the real problem. A page only shows the errors it has a slot
 * for, and most pages had slots for one or two fields — a rejected phone
 * number or delivery fee left the page looking as if nothing happened. So
 * every error key that no FieldError (or ValidationSummary) put on screen
 * is gathered into one dialog instead of being dropped.
 */
export function revealErrors(errors: Record<string, string>): void {
    const keys = Object.keys(errors);
    if (keys.length === 0) return;

    const shownFor = (key: string) =>
        document.querySelector(`[data-error-for="${CSS.escape(key)}"], [data-error-listed="${CSS.escape(key)}"]`);
    const unshown = keys.filter((key) => shownFor(key) === null);

    // Document order, not key order: "first error" means the one highest
    // on the page, whatever order the server listed them in.
    const anchors = keys
        .map((key) => document.querySelector<HTMLElement>(`[data-error-for="${CSS.escape(key)}"]`))
        .filter((node): node is HTMLElement => node !== null)
        .sort((a, b) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));

    const first = anchors[0];
    if (first) {
        const control = document.querySelector<HTMLElement>(`[aria-describedby~="${CSS.escape(first.id)}"]`);
        const focusable = control?.matches(FOCUSABLE) ? control : control?.querySelector<HTMLElement>(FOCUSABLE);

        (control ?? first).scrollIntoView({ behavior: 'smooth', block: 'center' });
        focusable?.focus({ preventScroll: true });
    }

    if (unshown.length > 0) {
        notifyValidation(unshown.map((key) => errors[key]));
    } else if (anchors.length > 0 && !isInViewport(anchors)) {
        notifyFieldErrors(anchors.length);
    }
}

// Several errors spread down a long form: once scrolled to the first, say
// how many there are so the rest aren't missed.
function isInViewport(nodes: HTMLElement[]): boolean {
    return nodes.every((node) => {
        const rect = node.getBoundingClientRect();

        return rect.top >= 0 && rect.bottom <= window.innerHeight;
    });
}

/**
 * Wired once from AdminLayout: every Inertia visit in the admin gets the
 * same error handling, whether the page used useForm, react-hook-form or
 * a bare router.post from a button.
 *
 * `t` is passed in rather than imported so the messages follow the locale
 * the layout is rendering in.
 */
export function listenForErrors(t: (key: string) => string): () => void {
    const offError = router.on('error', (event) => {
        const errors = event.detail.errors as Record<string, string>;
        // After React has rendered the new errors — the page's own state
        // (useForm, or an onError setState) lands on the next frames.
        window.setTimeout(() => revealErrors(errors), 60);
    });

    // A non-Inertia response: an abort(403), an expired CSRF token, a
    // crash. Inertia's default is to paint the raw HTML error page into a
    // full-screen modal; a plain message is what the user needs instead.
    // In development a 500 keeps that default — the stack trace is the
    // point there.
    const offInvalid = router.on('httpException', (event) => {
        const status = event.detail.response.status;
        const message =
            status === 403
                ? t('admin.errorForbidden')
                : status === 404
                  ? t('admin.errorNotFound')
                  : status === 419
                    ? t('admin.errorSessionExpired')
                    : status === 429
                      ? t('admin.errorTooManyRequests')
                      : status >= 500 && !import.meta.env.DEV
                        ? t('admin.errorServer')
                        : null;

        if (message !== null) {
            event.preventDefault();
            notifyError(message);
        }
    });

    const offException = router.on('networkError', (event) => {
        event.preventDefault();
        notifyError(t('admin.errorNetwork'));
    });

    return () => {
        offError();
        offInvalid();
        offException();
    };
}

/** Every dotted leaf path whose value differs between two snapshots. */
function changedPaths(before: unknown, after: unknown, prefix = ''): string[] {
    if (before === after) return [];

    const isContainer = (value: unknown) => typeof value === 'object' && value !== null && !(value instanceof File);
    if (!isContainer(before) || !isContainer(after)) return [prefix];

    const a = before as Record<string, unknown>;
    const b = after as Record<string, unknown>;
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    return [...keys].flatMap((key) => changedPaths(a[key], b[key], prefix === '' ? key : `${prefix}.${key}`));
}

/** An error belongs to an edit if either path contains the other (`items.0` ↔ `items.0.quantity`). */
function staleKeys(errorKeys: string[], changed: string[]): string[] {
    return errorKeys.filter((key) =>
        changed.some((path) => path === key || key.startsWith(`${path}.`) || path.startsWith(`${key}.`)),
    );
}

/**
 * Inertia useForm: drop a field's error as soon as that field is edited.
 * Watches `data` rather than wrapping setData, so no page has to change
 * how it writes to the form.
 */
export function useClearErrorsOnChange(
    data: object,
    errors: Partial<Record<string, string>>,
    clearErrors: (...fields: never[]) => void,
): void {
    const previous = useRef(data);

    useEffect(() => {
        const before = previous.current;
        previous.current = data;
        if (before === data) return;

        const stale = staleKeys(Object.keys(errors), changedPaths(before, data));
        if (stale.length > 0) clearErrors(...(stale as never[]));
        // errors/clearErrors are read, not reacted to: only an edit clears.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data]);
}

/**
 * react-hook-form pages keep server errors in their own state; same rule.
 * `aliases` maps a form field to the server key it is sent as, where the
 * two differ (`name_en` → `name.en`) — a lookup, or a function for
 * patterned names (every `items.3.*` → `items`).
 */
export function useClearServerErrorsOnChange(
    watch: (callback: (value: unknown, info: { name?: string }) => void) => { unsubscribe: () => void },
    setServerErrors: (update: (current: Record<string, string>) => Record<string, string>) => void,
    aliases: Record<string, string> | ((name: string) => string) = {},
): void {
    useEffect(() => {
        const subscription = watch((_, { name }) => {
            if (!name) return;
            const path = typeof aliases === 'function' ? aliases(name) : (aliases[name] ?? name);

            setServerErrors((current) => {
                const stale = staleKeys(Object.keys(current), [path]);
                if (stale.length === 0) return current;

                return Object.fromEntries(Object.entries(current).filter(([key]) => !stale.includes(key)));
            });
        });

        return () => subscription.unsubscribe();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [watch]);
}
