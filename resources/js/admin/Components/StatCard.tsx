import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

/**
 * Larkon's dashboard statistic tile, ported from Admin Template/index.html's
 * own "Total Orders"/"New Leads" cards: `card overflow-hidden` wrapping a
 * `card-body` two-column row (a `avatar-md bg-soft-*` icon tile on one side,
 * the label and figure on the other), with an optional
 * `card-footer py-2 bg-light bg-opacity-50` strip underneath.
 *
 * The template's footer carries a demo "+2.3% Last Week" delta. WAQAR has no
 * period-over-period series to compute one from, so the footer takes a real
 * caption and a real link instead of a fabricated trend — the slot is the
 * template's, the content is the application's.
 */
export default function StatCard({
    label,
    value,
    icon,
    variant = 'primary',
    caption,
    href,
    linkLabel,
}: {
    label: string;
    value: ReactNode;
    /** Boxicons class without the `bx ` prefix, e.g. `bx-cart-alt`. */
    icon: string;
    variant?: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info';
    caption?: string;
    href?: string;
    linkLabel?: string;
}) {
    return (
        // `h-100` keeps a row of tiles level when one figure wraps to two
        // lines (a formatted currency amount next to a bare count does).
        <div className="card overflow-hidden h-100">
            <div className="card-body">
                <div className="row align-items-center g-2">
                    <div className="col-auto">
                        <div className={`avatar-md bg-soft-${variant} rounded`}>
                            <i className={`bx ${icon} avatar-title fs-24 text-${variant}`} />
                        </div>
                    </div>
                    {/* Larkon splits this 6/6. A fixed half is too narrow for
                        Arabic labels, which run longer than the template's
                        English ones and were truncating mid-word — the icon
                        tile is a fixed 3.5rem, so giving it `col-auto` and
                        the text the remainder keeps the same visual balance
                        without clipping. */}
                    <div className="col text-end">
                        <p className="text-muted mb-0 text-truncate">{label}</p>
                        {/* Figures are rendered inside an LTR-isolated span:
                            Arabic is the admin's default direction and a bare
                            number next to punctuation (a currency amount, a
                            "12 / 30") reorders under the bidi algorithm. */}
                        <h3 className="text-dark mt-1 mb-0 text-nowrap" dir="ltr">
                            {value}
                        </h3>
                    </div>
                </div>
            </div>

            {(caption || href) && (
                <div className="card-footer py-2 bg-light bg-opacity-50">
                    <div className="d-flex align-items-center justify-content-between gap-2">
                        <span className="text-muted fs-12 text-truncate">{caption}</span>
                        {href && (
                            <Link href={href} className="text-reset fw-semibold fs-12 text-nowrap">
                                {linkLabel}
                            </Link>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
