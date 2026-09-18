import { useTranslation } from '../lib/useTranslation';

/**
 * A plain anchor rather than an Inertia `<Link>` — the export route
 * returns a binary .xlsx download, and routing it through Inertia's
 * fetch-based visit would try to parse the response as a page/JSON
 * payload instead of letting the browser save the file. `href` should
 * already carry whatever filters the list itself is currently applying,
 * so the download always matches what's on screen.
 */
export default function ExportButton({ href }: { href: string }) {
    const { t } = useTranslation();

    return (
        <a href={href} className="btn btn-sm btn-soft-secondary d-flex align-items-center">
            <i className="bx bx-download me-1" />
            {t('admin.exportExcel')}
        </a>
    );
}
