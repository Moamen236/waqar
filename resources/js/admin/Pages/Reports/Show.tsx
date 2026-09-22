import { Head, Link } from '@inertiajs/react';
import ReportCell, { type ReportColumnMeta } from '../../Components/ReportCell';
import ReportFilterBar, { type ReportFilters } from '../../Components/ReportFilterBar';
import { PaginationFooter } from '../../Components/Pagination';
import AdminLayout from '../../Layouts/AdminLayout';
import { useTranslation } from '../../lib/useTranslation';
import type { PaginatedData } from '../../types';

type Row = Record<string, unknown>;

interface ReportMeta {
    key: string;
    group: string;
    title: string;
    description: string;
    filters: string[];
    date_bases: string[];
    supports_comparison: boolean;
    notes: string[];
    default_sort: [string, string] | null;
}

/**
 * One page for every report.
 *
 * The table is driven entirely by `columns` from the server, so adding a
 * report adds no front-end code — which is the whole reason the column
 * metadata is a first-class object rather than JSX in thirty-nine pages.
 *
 * `notes` renders above the table rather than in a tooltip. They carry the
 * basis a figure is computed on — costed at current cost price, audit
 * retention window, and so on — and a caveat nobody sees is a caveat that
 * does not exist.
 */
export default function ReportShow({
    report,
    columns,
    rows,
    totals,
    filters,
    paginated,
    canExport,
    options,
}: {
    report: ReportMeta;
    columns: ReportColumnMeta[];
    rows: PaginatedData<Row> | Row[];
    totals: Row;
    filters: ReportFilters;
    paginated: boolean;
    canExport: boolean;
    options: Record<string, unknown>;
}) {
    const { t } = useTranslation();

    const data = paginated ? (rows as PaginatedData<Row>).data : (rows as Row[]);
    const hasTotals = totals && Object.keys(totals).length > 0;

    const query = new URLSearchParams(
        Object.entries(filters).flatMap(([key, value]) =>
            value === undefined || value === null || value === ''
                ? []
                : Array.isArray(value)
                  ? value.map((item) => [`${key}[]`, String(item)])
                  : [[key, String(value)]],
        ),
    ).toString();

    return (
        <AdminLayout title={t(report.title)}>
            <Head title={t(report.title)} />

            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div>
                    <h4 className="mb-0">{t(report.title)}</h4>
                    <p className="text-muted mb-0 small">{t(report.description)}</p>
                </div>

                <div className="d-flex gap-2">
                    <Link href={route('admin.reports.index')} className="btn btn-sm btn-soft-secondary">
                        <i className="bx bx-arrow-back me-1" />
                        {t('reports.backToCatalogue')}
                    </Link>

                    <a
                        href={`${route('admin.reports.print', report.key)}?${query}`}
                        className="btn btn-sm btn-soft-secondary"
                        target="_blank"
                        rel="noreferrer"
                    >
                        <i className="bx bx-printer me-1" />
                        {t('reports.print')}
                    </a>

                    {canExport && (
                        <>
                            {/* Plain anchors, not Inertia links: the export
                                returns a binary download, and routing it
                                through a fetch-based visit would try to parse
                                the file as a page payload. Same reason
                                ExportButton is an <a>. */}
                            <a
                                href={`${route('admin.reports.export', report.key)}?${query}`}
                                className="btn btn-sm btn-soft-secondary"
                            >
                                <i className="bx bx-download me-1" />
                                {t('reports.exportExcel')}
                            </a>
                            <a
                                href={`${route('admin.reports.export', report.key)}?${query}&format=csv`}
                                className="btn btn-sm btn-soft-secondary"
                            >
                                <i className="bx bx-download me-1" />
                                {t('reports.exportCsv')}
                            </a>
                        </>
                    )}
                </div>
            </div>

            <ReportFilterBar
                reportKey={report.key}
                available={report.filters}
                dateBases={report.date_bases}
                supportsComparison={report.supports_comparison}
                filters={filters}
                options={options}
            />

            {report.notes.length > 0 && (
                <div className="alert alert-info py-2 px-3 small">
                    {report.notes.map((note) => (
                        <div key={note}>
                            <i className="bx bx-info-circle me-1" />
                            {t(note)}
                        </div>
                    ))}
                </div>
            )}

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover table-centered mb-0">
                        <thead className="bg-light bg-opacity-50">
                            <tr>
                                {columns.map((column) => (
                                    <th
                                        key={column.key}
                                        className={
                                            ['number', 'money', 'percent'].includes(column.type)
                                                ? 'text-end'
                                                : undefined
                                        }
                                    >
                                        {t(column.label)}
                                    </th>
                                ))}
                            </tr>
                        </thead>

                        <tbody>
                            {data.length === 0 ? (
                                <tr>
                                    <td colSpan={columns.length} className="text-center text-muted py-4">
                                        {t('reports.noRows')}
                                    </td>
                                </tr>
                            ) : (
                                data.map((row, index) => (
                                    <tr key={index}>
                                        {columns.map((column) => (
                                            <ReportCell key={column.key} value={row[column.key]} column={column} />
                                        ))}
                                    </tr>
                                ))
                            )}
                        </tbody>

                        {hasTotals && data.length > 0 && (
                            <tfoot className="bg-light bg-opacity-50 fw-semibold">
                                <tr>
                                    {columns.map((column, index) => (
                                        <ReportCell
                                            key={column.key}
                                            value={index === 0 ? t('reports.total') : totals[column.key]}
                                            column={index === 0 ? { ...column, type: 'text' } : column}
                                        />
                                    ))}
                                </tr>
                            </tfoot>
                        )}
                    </table>
                </div>

                {paginated && <PaginationFooter data={rows as PaginatedData<Row>} />}
            </div>
        </AdminLayout>
    );
}
