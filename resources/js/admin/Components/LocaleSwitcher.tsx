import { Link, usePage } from '@inertiajs/react';
import Dropdown from 'react-bootstrap/Dropdown';
import { useTranslation } from '../lib/useTranslation';
import type { SharedProps } from '../types';

/** Each language named in itself, never translated — the point of the
 *  label is to be recognised by someone who does not read the current
 *  language. */
const labels: Record<string, string> = { ar: 'العربية', en: 'English' };

/**
 * Arabic/English switcher for the admin topbar.
 *
 * Question 2 scoped staff to Arabic-only for v1, and Phase 6 kept the
 * English catalog complete alongside it precisely so this stayed a UI
 * change rather than a translation project. It is — `/en/admin/…` has
 * routed and been tested since Phase 6; this only exposes it.
 *
 * Same mechanism as the storefront's switcher (Section 16: "never a
 * separate architecture"), and the same two non-obvious reasons for it:
 *
 * - It navigates to the **sibling URL** under the other locale prefix
 *   rather than re-rendering the current one, because the URL is the
 *   single source of truth for locale (Q20). The sibling is built
 *   server-side (HandleInertiaRequests::alternates), so query strings
 *   survive — switching language mid-filter keeps the filter.
 * - It is a **full document load**, not an Inertia visit. `lang`/`dir`
 *   live on the root view, and the admin swaps Larkon's LTR stylesheet
 *   for `app-rtl.min.css` there too, so direction only flips when the
 *   document itself is re-rendered.
 */
export default function LocaleSwitcher() {
    const { locale } = usePage<SharedProps>().props;
    const { t } = useTranslation();

    return (
        <Dropdown align="end" className="topbar-item">
            <Dropdown.Toggle
                as="button"
                type="button"
                className="topbar-button d-flex align-items-center gap-1 border-0 bg-transparent"
                aria-label={t('admin.language')}
            >
                <i className="bx bx-globe fs-24 align-middle" />
                <span className="d-none d-md-inline">{labels[locale.current] ?? locale.current}</span>
            </Dropdown.Toggle>

            <Dropdown.Menu className="dropdown-menu-end">
                {locale.supported.map((code) => (
                    <Dropdown.Item
                        key={code}
                        as={Link}
                        href={locale.alternates[code]}
                        preserveState={false}
                        preserveScroll={false}
                        active={code === locale.current}
                        lang={code}
                    >
                        {labels[code] ?? code}
                        {code === locale.current && <i className="bx bx-check ms-1 align-middle" />}
                    </Dropdown.Item>
                ))}
            </Dropdown.Menu>
        </Dropdown>
    );
}
