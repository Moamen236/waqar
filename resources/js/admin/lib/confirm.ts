import Swal, { type SweetAlertOptions } from 'sweetalert2';
import ar from '../locales/ar.json';
import en from '../locales/en.json';
import { createTranslator, type Catalog, type Locale } from '../../lib/i18n';

/**
 * One shared confirm-dialog helper (SweetAlert2, Section 23's UI library
 * list) rather than every page instantiating its own config — used
 * before every status-changing action a Checking/Delivery/Accounting
 * employee takes (confirm, cancel, mark delivered, etc.).
 *
 * These fire from event handlers, outside React's tree, so they cannot
 * read the locale off Inertia's shared props the way `useTranslation`
 * does. They read it off the document instead — `admin.blade.php` stamps
 * `<html lang dir>` from the same app locale the props come from, so the
 * two agree by construction.
 *
 * Two things were wrong before: every dialog's chrome ("Confirm",
 * "Cancel", "Done", "Something went wrong") was hard-coded English in an
 * Arabic-by-default admin, and the dialog laid out LTR regardless of
 * direction. Colors now come from the brand palette rather than
 * Bootstrap's stock `#0d6efd`, so a confirm button in the modal matches
 * the `btn-primary` on the page behind it.
 */

const catalogs: Record<Locale, Catalog> = { ar, en };

/** Brand palette, mirroring resources/css/admin.css's --bs-primary override. */
const BRAND_PRIMARY = '#012c4e';
const DANGER = '#dc3545';

function locale(): Locale {
    const lang = typeof document !== 'undefined' ? document.documentElement.lang : 'ar';

    return lang === 'en' ? 'en' : 'ar';
}

function chrome(): { t: ReturnType<typeof createTranslator>; base: SweetAlertOptions } {
    const current = locale();

    return {
        t: createTranslator(catalogs[current] ?? catalogs.ar, catalogs.en),
        base: {
            // SweetAlert2 mirrors its own layout off this, including which
            // side the confirm button sits on.
            ...(current === 'ar' ? { customClass: { popup: 'swal2-rtl' } } : {}),
        },
    };
}

export async function confirmAction(options: {
    title: string;
    text?: string;
    confirmText?: string;
    danger?: boolean;
}): Promise<boolean> {
    const { t, base } = chrome();

    const result = await Swal.fire({
        ...base,
        title: options.title,
        text: options.text,
        icon: options.danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: options.confirmText ?? t('admin.confirm'),
        confirmButtonColor: options.danger ? DANGER : BRAND_PRIMARY,
        cancelButtonText: t('admin.cancel'),
        reverseButtons: true,
    });

    return result.isConfirmed;
}

/**
 * Corner toasts for news that needs no acknowledgement — a save went
 * through, or a heads-up. Errors stay as dialogs (below): a refused action
 * is something the user has to read before carrying on.
 */
function toast(icon: 'success' | 'warning' | 'info', text: string, timer: number): void {
    const { base } = chrome();
    const rtl = locale() === 'ar';

    void Swal.fire({
        ...base,
        toast: true,
        position: rtl ? 'top-start' : 'top-end',
        icon,
        title: text,
        timer,
        timerProgressBar: true,
        showConfirmButton: false,
        showCloseButton: true,
    });
}

export function notifySuccess(text: string): void {
    toast('success', text, 3000);
}

export function notifyWarning(text: string): void {
    toast('warning', text, 6000);
}

export function notifyInfo(text: string): void {
    toast('info', text, 4000);
}

/**
 * Validation messages that have no field on screen to sit under — the
 * safety net for server errors the page doesn't render (see
 * lib/formErrors.ts). Built with DOM nodes, not an html string, so a
 * message can never inject markup.
 */
export function notifyValidation(messages: string[]): void {
    const { t, base } = chrome();

    const list = document.createElement('ul');
    list.className = 'text-start mb-0 ps-3';
    for (const message of messages) {
        const item = document.createElement('li');
        item.textContent = message;
        list.appendChild(item);
    }

    void Swal.fire({
        ...base,
        icon: 'error',
        title: t('admin.pleaseFixTheseErrors'),
        html: list,
        confirmButtonColor: BRAND_PRIMARY,
        confirmButtonText: t('admin.close'),
    });
}

/** The fields are marked on the page; this just says so, briefly. */
export function notifyFieldErrors(count: number): void {
    const { t } = chrome();

    toast('warning', t('admin.fixHighlightedFields', { count }), 5000);
}

export function notifyError(text: string): void {
    const { t, base } = chrome();

    void Swal.fire({
        ...base,
        icon: 'error',
        title: t('admin.somethingWentWrong'),
        text,
        confirmButtonColor: BRAND_PRIMARY,
        confirmButtonText: t('admin.close'),
    });
}
