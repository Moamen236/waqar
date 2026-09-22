import type { FormEvent } from 'react';
import { useTranslation } from '../lib/useTranslation';

/**
 * The date window on a history list. Defaults to today server-side (see
 * App\Support\DateRangeFilter), so this renders today's date on first
 * load rather than an empty pair of boxes — the default has to be
 * visible, or a list that looks empty reads as a broken list.
 *
 * "All dates" submits an *empty* date_from rather than dropping the
 * parameter. Absence means "hasn't chosen" and gets the default back;
 * only an explicit empty value opens the window, which is what makes the
 * two states expressible in one query string.
 *
 * Uncontrolled with a `key` off the applied values, the same pattern the
 * order book's filter form uses: applying remounts with fresh defaults,
 * while typing never fights a re-render.
 */
export default function DateRangeFilter({
    from,
    to,
    onApply,
}: {
    from: string | null;
    to: string | null;
    onApply: (range: { date_from: string; date_to: string }) => void;
}) {
    const { t } = useTranslation();

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const form = new FormData(e.currentTarget);
        onApply({
            date_from: String(form.get('date_from') ?? ''),
            date_to: String(form.get('date_to') ?? ''),
        });
    }

    return (
        <form className="d-flex flex-wrap align-items-end gap-2" key={`${from ?? ''}|${to ?? ''}`} onSubmit={submit}>
            <div>
                <label className="form-label mb-1 fs-13">{t('admin.dateFrom')}</label>
                <input
                    type="date"
                    name="date_from"
                    className="form-control form-control-sm"
                    defaultValue={from ?? ''}
                />
            </div>
            <div>
                <label className="form-label mb-1 fs-13">{t('admin.dateTo')}</label>
                <input type="date" name="date_to" className="form-control form-control-sm" defaultValue={to ?? ''} />
            </div>
            <button type="submit" className="btn btn-sm btn-primary">
                {t('admin.applyFilters')}
            </button>
            <button
                type="button"
                className="btn btn-sm btn-soft-secondary"
                disabled={from === null && to === null}
                onClick={() => onApply({ date_from: '', date_to: '' })}
            >
                {t('admin.allDates')}
            </button>
        </form>
    );
}
