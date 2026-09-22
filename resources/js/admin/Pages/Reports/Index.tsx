import { Head, Link } from '@inertiajs/react';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';

interface ReportCard {
    key: string;
    title: string;
    description: string;
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
