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

export function notifySuccess(text: string): void {
    const { t, base } = chrome();

    void Swal.fire({ ...base, icon: 'success', title: t('admin.done'), text, timer: 2500, showConfirmButton: false });
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
