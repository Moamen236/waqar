import { Head, Link } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';

interface ReportCard {
    key: string;
    title: string;
    description: string;
}

/**
 * Each group opens as a landing page — a large icon, the group's name and
 * what it is for, then its reports two to a row, each with its own icon.
 * Group icons match the sidebar; a report missing from its map falls back
 * to a plain file icon, so a newly registered report still renders.
 */
const GROUP_PAGES: Record<string, { icon: string; reports: Record<string, string> }> = {
    executive: {
        icon: 'bx-trending-up',
        reports: {},
    },
    sales: {
        icon: 'bx-line-chart',
        reports: {
            'sales.summary': 'bx-line-chart',
            'sales.by-product': 'bx-purchase-tag',
            'sales.by-category': 'bx-category',
            'sales.by-geography': 'bx-map',
            'sales.customers': 'bx-group',
            'sales.discounts': 'bxs-coupon',
            'sales.margin': 'bx-trending-up',
        },
    },
    inventory: {
        icon: 'bx-package',
        reports: {
            'inventory.stock-on-hand': 'bx-box',
            'inventory.low-stock': 'bx-error',
            'inventory.movements': 'bx-transfer',
            'inventory.reservations': 'bx-lock-alt',
            'inventory.shrinkage': 'bx-trending-down',
            'inventory.turnover': 'bx-refresh',
        },
    },
    returns: {
        icon: 'bx-undo',
        reports: {
            'returns.summary': 'bx-bar-chart-alt-2',
            'returns.reasons': 'bx-message-square-detail',
            'returns.by-product': 'bx-package',
            'returns.cycle-time': 'bx-time-five',
            'returns.refunds': 'bx-money',
        },
    },
    finance: {
        icon: 'bx-wallet',
        reports: {
            'finance.profit-loss': 'bx-line-chart',
            'finance.collections': 'bx-money',
            'finance.receivables': 'bx-hourglass',
            'finance.treasury': 'bx-wallet',
            'finance.transfers': 'bx-transfer-alt',
            'finance.reconciliation': 'bx-check-double',
            'finance.expenses': 'bx-credit-card',
        },
    },
    employees: {
        icon: 'bx-user-check',
        reports: {
            'employees.customer-service': 'bx-headphone',
            'employees.checking': 'bx-check-square',
            'employees.delivery': 'bxs-truck',
            'employees.warehouse': 'bx-store',
            'employees.accounting': 'bx-calculator',
        },
    },
    audit: {
        icon: 'bx-search-alt',
        reports: {
            'audit.trail': 'bx-history',
            'audit.entity-history': 'bx-file-find',
            'audit.access': 'bx-shield-quarter',
        },
    },
    orders: {
        icon: 'bx-receipt',
        reports: {
            'orders.summary': 'bx-bar-chart-alt-2',
            'orders.pipeline': 'bx-filter-alt',
            'orders.lifecycle': 'bx-time-five',
            'orders.delivery-performance': 'bxs-truck',
            'orders.cancellations': 'bx-x-circle',
            'orders.source-comparison': 'bx-git-compare',
        },
    },
};

function GroupPage({ groupKey, reports }: { groupKey: string; reports: ReportCard[] }) {
    const { t } = useTranslation();
    const page = GROUP_PAGES[groupKey];

    return (
        <div className="card">
            <div className="card-body p-4 p-lg-5">
                <div className="mx-auto" style={{ maxWidth: 880 }}>
                    <i className={`bx ${page.icon} text-dark d-block mb-2`} style={{ fontSize: 72, lineHeight: 1 }} />
                    <h1 className="fw-bold mb-2">{t(`reports.group.${groupKey}`)}</h1>
                    <p className="text-muted fs-15 mb-5">{t(`reports.groupIntro.${groupKey}`)}</p>

                    {/* justify-content-center: full rows fill both columns,
                        an odd last report sits centred under them. */}
                    <div className="row justify-content-center g-4 g-lg-5">
                        {reports.map((report) => (
                            <div className="col-md-6" key={report.key}>
                                <Link
                                    href={route('admin.reports.show', report.key)}
                                    className="d-block text-decoration-none report-group-link"
                                >
                                    <h5 className="d-flex align-items-center gap-2 fw-semibold text-dark mb-2">
                                        <i className={`bx ${page.reports[report.key] ?? 'bx-file'} fs-20`} />
                                        <span className="report-group-title">{t(report.title)}</span>
                                    </h5>
                                    <p className="text-muted mb-0">{t(report.description)}</p>
                                </Link>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

/**
 * The report catalogue.
 *
 * Groups arrive already filtered to what this employee may open — the
 * registry checks each report's permission server-side, so a Checking
 * employee has no Finance section at all rather than a section full of
 * locked cards. That is the same choice `DashboardController` makes with
 * its tiles: absent, not disabled.
 */
export default function ReportsIndex({ groups, group }: { groups: Record<string, ReportCard[]>; group: string }) {
    const { t } = useTranslation();

    const entries = Object.entries(groups).filter(([key]) => !group || key === group);
    const hasReports = entries.some(([, reports]) => reports.length > 0);

    // Two different empty states, deliberately not one message.
    //
    // `[].every()` is true, so folding these together reported "you have no
    // access" whenever a group simply had no reports registered in it yet —
    // which is alarming and wrong, and is exactly what a Super Admin saw
    // when opening a group that has not been built out.
    const noAccessAtAll = Object.keys(groups).length === 0;

    return (
        <AdminLayout title={t('reports.title')}>
            <Head title={t('reports.title')} />

            {!hasReports ? (
                <div className="card">
                    <div className="card-body text-center text-muted py-5">
                        <i className="bx bx-bar-chart-alt-2 fs-1 d-block mb-2" />
                        <div>{noAccessAtAll ? t('reports.empty') : t('reports.groupEmpty')}</div>

                        {!noAccessAtAll && group && (
                            <Link href={route('admin.reports.index')} className="btn btn-sm btn-soft-secondary mt-3">
                                {t('reports.backToCatalogue')}
                            </Link>
                        )}
                    </div>
                </div>
            ) : group && GROUP_PAGES[group] && groups[group] ? (
                <GroupPage groupKey={group} reports={groups[group]} />
            ) : (
                entries.map(([groupKey, reports]) => (
                    <div className="mb-3" key={groupKey}>
                        <h5 className="mb-2">{t(`reports.group.${groupKey}`)}</h5>

                        <div className="row g-3">
                            {reports.map((report) => (
                                <div className="col-md-6 col-xl-4" key={report.key}>
                                    <Link
                                        href={route('admin.reports.show', report.key)}
                                        className="card h-100 text-decoration-none"
                                    >
                                        <div className="card-body">
                                            <h6 className="mb-1 text-body">{t(report.title)}</h6>
                                            <p className="text-muted mb-0 small">{t(report.description)}</p>
                                        </div>
                                    </Link>
                                </div>
                            ))}
                        </div>
                    </div>
                ))
            )}
        </AdminLayout>
    );
}
