import { useTranslation } from '../lib/useTranslation';

export interface ReportColumnMeta {
    key: string;
    label: string;
    type: 'text' | 'number' | 'money' | 'percent' | 'date' | 'datetime' | 'enum';
    sortable: boolean;
    enum_prefix: string | null;
}

/**
 * One report cell, formatted by the column's declared type.
 *
 * Formatting lives here rather than in each report's own page because the
 * server deliberately sends raw values: money goes out as a decimal, not a
 * currency string, so the same payload can feed an Excel column that the
 * recipient can still sum. Presentation is the browser's job, and the
 * browser knows the locale.
 *
 * Null is rendered as an em dash, never as zero. A cancellation rate with
 * no orders behind it is "no data", and showing 0% would read as "nothing
 * was cancelled" — a different and wrong statement.
 */
export function formatCell(
    value: unknown,
    column: ReportColumnMeta,
    helpers: {
        t: (key: string) => string;
        price: (value: number | null | undefined) => string;
        date: (value: string | null | undefined) => string;
        dateTime: (value: string | null | undefined) => string;
        locale: string;
    },
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    switch (column.type) {
        case 'money':
            return helpers.price(Number(value));
        case 'percent':
            return `${Number(value).toFixed(1)}%`;
        case 'number':
            return Number(value).toLocaleString(helpers.locale === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US');
        case 'date':
            return helpers.date(String(value));
        case 'datetime':
            return helpers.dateTime(String(value));
        case 'enum':
            return column.enum_prefix ? helpers.t(`${column.enum_prefix}${value}`) : String(value);
        default:
            return String(value);
    }
}

export default function ReportCell({ value, column }: { value: unknown; column: ReportColumnMeta }) {
    const { t, price, date, dateTime, locale } = useTranslation();
    const numeric = ['number', 'money', 'percent'].includes(column.type);

    return (
        <td className={numeric ? 'text-end' : undefined}>
            {formatCell(value, column, { t, price, date, dateTime, locale })}
        </td>
    );
}
