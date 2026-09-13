import { Link } from '@inertiajs/react';

export interface Crumb {
    /** Already-translated label — callers translate before passing, not a key. */
    label: string;
    /** Omitted on the last crumb, which renders as the active page. */
    href?: string;
}

/**
 * Larkon's breadcrumb (Admin Template/ui-breadcrumb.html): a plain
 * `nav > ol.breadcrumb` with the trailing crumb carrying `.active` and
 * `aria-current`. `.py-0` is the template's own modifier for the compact
 * variant it uses inside a page header rather than a demo card.
 *
 * Bootstrap draws the separator with `::before` on each non-first item
 * and its own `--bs-breadcrumb-divider` already mirrors under `dir=rtl`
 * (Larkon ships a real RTL build), so no direction handling is needed
 * here — the divider follows the document, not this component.
 */
export default function Breadcrumb({ items }: { items: Crumb[] }) {
    if (items.length === 0) return null;

    return (
        <nav aria-label="breadcrumb">
            <ol className="breadcrumb mb-0 py-0">
                {items.map((item, index) => {
                    const last = index === items.length - 1;

                    return (
                        <li
                            key={`${item.label}-${index}`}
                            className={`breadcrumb-item${last ? ' active' : ''}`}
                            aria-current={last ? 'page' : undefined}
                        >
                            {item.href && !last ? <Link href={item.href}>{item.label}</Link> : item.label}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
