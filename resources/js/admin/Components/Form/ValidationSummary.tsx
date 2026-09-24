import { useTranslation } from '../../lib/useTranslation';
import { errorId, fieldId } from '../../lib/formErrors';
import AlertMessage from './AlertMessage';

/**
 * Every error on the form, listed at the top — for long, multi-card forms
 * where the first error may be several screens from the next. Each entry
 * whose field is on the page is a link that jumps to it.
 *
 * `data-error-listed` tells revealErrors() these messages are on screen,
 * so it doesn't repeat them in the fallback dialog.
 */
export default function ValidationSummary({ errors }: { errors: Partial<Record<string, string>> }) {
    const { t } = useTranslation();
    const entries = Object.entries(errors).filter((entry): entry is [string, string] => Boolean(entry[1]));

    if (entries.length === 0) return null;

    function jump(key: string) {
        const control =
            document.querySelector<HTMLElement>(`[aria-describedby~="${CSS.escape(errorId(fieldId(key)))}"]`) ??
            document.querySelector<HTMLElement>(`[data-error-for="${CSS.escape(key)}"]`);

        control?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        control?.focus({ preventScroll: true });
    }

    return (
        <AlertMessage variant="danger" title={t('admin.pleaseFixTheseErrors')}>
            <ul className="mb-0 ps-3 mt-1">
                {entries.map(([key, message]) => (
                    <li key={key} data-error-listed={key}>
                        <button
                            type="button"
                            className="btn btn-link p-0 text-reset text-start align-baseline"
                            onClick={() => jump(key)}
                        >
                            {message}
                        </button>
                    </li>
                ))}
            </ul>
        </AlertMessage>
    );
}
