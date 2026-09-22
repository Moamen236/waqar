import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { formatCell, type ReportColumnMeta } from '../../Components/ReportCell';
import { useTranslation } from '../../lib/useTranslation';

type Row = Record<string, unknown>;

/**
 * The print view: the same rows, no chrome.
 *
 * No AdminLayout — a printout with a sidebar in it is a wasted page. The
 * stylesheet is inline and scoped to this page rather than living in
 * admin.css, because nothing else needs it and a print rule loose in the
 * global sheet is the kind of thing that silently breaks another screen's
 * printing a year from now.
 *
 * PDF is the browser's Save-as-PDF, deliberately. It is the same approach
 * `/admin/orders/{order}/invoice` already takes, it renders this app's
 * Arabic and RTL correctly with no extra work, and it adds no dependency.
 */
export default function ReportPrint({
    report,
    columns,
    rows,
    totals,
    filters,
    generatedBy,
    generatedAt,
}: {
    report: { key: string; title: string; description: string; notes: string[] };
    columns: ReportColumnMeta[];
    rows: Row[];
    totals: Row;
    filters: Record<string, unknown>;
    generatedBy: string;
    generatedAt: string;
}) {
    const { t, price, date, dateTime, locale } = useTranslation();
    const helpers = { t, price, date, dateTime, locale };
    const hasTotals = totals && Object.keys(totals).length > 0;

    // Open the dialog once the table has painted. If the operator cancels,
    // they still have a clean page on screen to read.
    useEffect(() => {
        const timer = window.setTimeout(() => window.print(), 400);

        return () => window.clearTimeout(timer);
    }, []);

    const periodLabel =
        filters.preset === 'custom'
            ? `${filters.date_from ?? ''} — ${filters.date_to ?? ''}`
            : t(`reports.preset.${filters.preset ?? 'this_month'}`);

    return (
        <>
            <Head title={t(report.title)} />

            <style>{`
                @page { size: A4 landscape; margin: 12mm; }
                body { background: #fff; }
                .report-print { padding: 16px; font-size: 12px; color: #1f2937; }
                .report-print h1 { font-size: 18px; margin: 0 0 4px; }
                .report-print .meta { color: #6b7280; font-size: 11px; margin-bottom: 12px; }
                .report-print table { width: 100%; border-collapse: collapse; }
                .report-print th, .report-print td { border: 1px solid #d1d5db; padding: 5px 7px; }
                .report-print th { background: #f3f4f6; text-align: start; }
                .report-print td.num, .report-print th.num { text-align: end; }
                .report-print tfoot td { background: #f9fafb; font-weight: 600; }
                /* Repeat the header on every printed page, and never split
                   the totals row across a page break. */
                .report-print thead { display: table-header-group; }
                .report-print tfoot { display: table-row-group; }
                .report-print tr { page-break-inside: avoid; }
                @media print { .no-print { display: none !important; } }
            `}</style>

            <div className="report-print">
                <button type="button" className="btn btn-sm btn-primary no-print mb-3" onClick={() => window.print()}>
                    {t('reports.print')}
                </button>

                <h1>{t(report.title)}</h1>

                <div className="meta">
                    <div>
                        {t('reports.filter.period')}: {periodLabel}
                    </div>
                    <div>
                        {t('reports.meta.generated_by')}: {generatedBy} · {t('reports.meta.generated_at')}:{' '}
                        {generatedAt}
                    </div>
                    {report.notes.map((note) => (
                        <div key={note}>{t(note)}</div>
                    ))}
                </div>

                <table>
                    <thead>
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    className={['number', 'money', 'percent'].includes(column.type) ? 'num' : undefined}
                                >
                                    {t(column.label)}
                                </th>
                            ))}
                        </tr>
                    </thead>

                    <tbody>
                        {rows.map((row, index) => (
                            <tr key={index}>
                                {columns.map((column) => (
                                    <td
                                        key={column.key}
                                        className={
                                            ['number', 'money', 'percent'].includes(column.type) ? 'num' : undefined
                                        }
                                    >
                                        {formatCell(row[column.key], column, helpers)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>

                    {hasTotals && rows.length > 0 && (
                        <tfoot>
                            <tr>
                                {columns.map((column, index) => (
                                    <td
                                        key={column.key}
                                        className={
                                            ['number', 'money', 'percent'].includes(column.type) ? 'num' : undefined
                                        }
                                    >
                                        {index === 0
                                            ? t('reports.total')
                                            : formatCell(totals[column.key], column, helpers)}
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </>
    );
}
